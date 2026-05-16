<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\web\Controller;
use markhuot\craftai\fields\CodeComponent;
use markhuot\craftai\fields\CodeComponentPermissions;
use markhuot\craftai\fields\CodeComponentValue;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP-only HTTP endpoints that back the React editor for {@see CodeComponent}
 * fields. The two routes here exist because the agent writes to the field
 * out-of-band — through the `update_code_component` tool that hits
 * `Elements::saveElement` directly — bypassing the form the user has open
 * in the CP. Without these routes, two problems compound:
 *
 *   - the React tabs keep showing whatever was on disk at page load, so a
 *     subsequent user Save overwrites the agent's writes;
 *   - a freshly minted chat session id only persists when the user happens
 *     to trigger Craft's autosave, so navigating away mid-conversation
 *     orphans the thread.
 *
 * The `state` route polls the source of truth (the element on disk) so the
 * React state stays in sync with whatever the agent has written. The
 * `persist-session` route writes just the session pointer back without
 * round-tripping through the form, so the thread is durable from the
 * moment the user clicks "Start chatting".
 */
class CodeComponentController extends Controller
{
    /**
     * GET craft-ai/code-component/state?(entryId|draftId)=N&fieldHandle=...
     *
     * Returns the current persisted tab values for the field so the React
     * editor can resolve "did the agent just write to me?" without having
     * to peek at the agent's tool calls.
     */
    public function actionState(): Response
    {
        [$element, , $handle] = $this->resolveTarget();

        $value = $element->getFieldValue($handle);
        if (! $value instanceof CodeComponentValue) {
            $value = new CodeComponentValue();
        }

        return $this->asJson([
            'twig' => $value->twig,
            'css' => $value->css,
            'js' => $value->js,
            'agentSessionId' => $value->agentSessionId,
        ]);
    }

    /**
     * POST craft-ai/code-component/persist-session
     *   body: (entryId|draftId), fieldHandle, sessionId
     *
     * Writes the supplied session UUID into the field's persisted JSON
     * without touching the other tab values. The user-facing form may
     * still have unsaved edits in the Twig/CSS/JS tabs; we read those
     * back from disk first so this targeted save can't clobber them.
     */
    public function actionPersistSession(): Response
    {
        $this->requirePostRequest();

        [$element, , $handle] = $this->resolveTarget();

        $rawSession = $this->request->getBodyParam('sessionId');
        $sessionId = is_string($rawSession) ? trim($rawSession) : '';
        if ($sessionId === '') {
            throw new BadRequestHttpException('sessionId is required.');
        }

        // Permission gate matches the field's CP-side guards. We require
        // the prompt permission specifically here — minting a session is
        // a Prompt-tab affordance — even though no tab content is being
        // mutated. An admin always passes.
        $permissions = CodeComponentPermissions::resolve(Craft::$app->getUser()->getIdentity());
        if (! $permissions['prompt']) {
            throw new ForbiddenHttpException('You do not have permission to start a Code Component chat session.');
        }

        $value = $element->getFieldValue($handle);
        if (! $value instanceof CodeComponentValue) {
            $value = new CodeComponentValue();
        }
        $value->agentSessionId = $sessionId;
        $element->setFieldValue($handle, $value);

        if (! Craft::$app->getElements()->saveElement($element)) {
            $errors = implode('; ', $element->getErrorSummary(true));

            return $this->asJson([
                'ok' => false,
                'error' => $errors !== '' ? $errors : 'Could not save the element.',
            ]);
        }

        return $this->asJson(['ok' => true]);
    }

    /**
     * Resolve the element + field this request targets. Centralized here so
     * both actions share the same lookup + validation rules: exactly one of
     * `entryId`/`draftId` must resolve to an element on disk, and
     * `fieldHandle` must reference a CodeComponent on that element's
     * field layout. Returns the handle as a separate `string` so callers
     * can pass it to `getFieldValue`/`setFieldValue` without re-narrowing
     * `$field->handle` (which is nullable on the Field model).
     *
     * @return array{0: ElementInterface, 1: CodeComponent, 2: string}
     */
    private function resolveTarget(): array
    {
        $entryId = $this->paramAsInt('entryId');
        $draftId = $this->paramAsInt('draftId');
        $rawHandle = $this->request->getQueryParam('fieldHandle')
            ?? $this->request->getBodyParam('fieldHandle');
        $fieldHandle = is_string($rawHandle) ? trim($rawHandle) : '';

        if ($fieldHandle === '') {
            throw new BadRequestHttpException('fieldHandle is required.');
        }

        $element = null;
        if ($draftId !== null) {
            $element = Entry::find()->draftId($draftId)->status(null)->one();
        } elseif ($entryId !== null) {
            $element = Entry::find()->id($entryId)->status(null)->one();
        }

        if (! $element instanceof ElementInterface) {
            throw new NotFoundHttpException('No matching element.');
        }

        $field = $element->getFieldLayout()?->getFieldByHandle($fieldHandle);
        if (! $field instanceof CodeComponent) {
            throw new NotFoundHttpException("Field \"{$fieldHandle}\" is not a Code Component on this element.");
        }

        return [$element, $field, $fieldHandle];
    }

    private function paramAsInt(string $name): ?int
    {
        $raw = $this->request->getQueryParam($name) ?? $this->request->getBodyParam($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }
}
