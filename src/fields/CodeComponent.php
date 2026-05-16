<?php

namespace markhuot\craftai\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use markhuot\craftai\web\assets\codecomponent\CodeComponentFieldAsset;
use yii\db\Schema;

/**
 * A field that stores a small component made of three authoring tabs —
 * Twig, CSS, and JavaScript — plus an agent-driven Prompt tab in the
 * control panel UI. Values round-trip as JSON; renderable via
 * `{{ entry.<handle>.render() }}` from a template.
 *
 * Per-tab Craft permissions gate who can see and edit each tab in the
 * CP. Admins implicitly see all four; non-admins only see the ones their
 * permissions allow. The agent's writeback tool respects the same gates.
 */
class CodeComponent extends Field
{
    public static function displayName(): string
    {
        return 'Code Component';
    }

    public static function icon(): string
    {
        return 'code';
    }

    public static function phpType(): string
    {
        return CodeComponentValue::class.'|null';
    }

    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof CodeComponentValue) {
            $value->element = $value->element ?? $element;

            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = Json::decodeIfJson($value);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            $value = [];
        }

        $instance = new CodeComponentValue();
        $instance->twig = self::stringFrom($value, 'twig');
        $instance->css = self::stringFrom($value, 'css');
        $instance->js = self::stringFrom($value, 'js');
        $rawAgentSessionId = $value['agentSessionId'] ?? null;
        $instance->agentSessionId = is_string($rawAgentSessionId) && $rawAgentSessionId !== ''
            ? $rawAgentSessionId
            : null;
        $instance->element = $element;

        return $instance;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof CodeComponentValue) {
            return $value->toArray();
        }

        // Defensive: should never hit, but if a raw array survives the
        // normalize path we still emit the canonical shape.
        if (is_array($value)) {
            $rawAgentSessionId = $value['agentSessionId'] ?? null;

            return [
                'twig' => self::stringFrom($value, 'twig'),
                'css' => self::stringFrom($value, 'css'),
                'js' => self::stringFrom($value, 'js'),
                'agentSessionId' => is_string($rawAgentSessionId) && $rawAgentSessionId !== ''
                    ? $rawAgentSessionId
                    : null,
            ];
        }

        return ['twig' => '', 'css' => '', 'js' => '', 'agentSessionId' => null];
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $value = $value instanceof CodeComponentValue ? $value : new CodeComponentValue();
        $permissions = CodeComponentPermissions::resolve(Craft::$app->getUser()->getIdentity());

        if (! $permissions['twig'] && ! $permissions['css'] && ! $permissions['js'] && ! $permissions['prompt']) {
            return Html::tag('p', 'You do not have permission to edit any tabs of this field.', [
                'class' => 'light',
            ]);
        }

        // Mount the React app and hand it the data it needs through a JSON
        // bootstrap. Hidden inputs named `<handle>[twig|css|js|agentSessionId]`
        // travel with the form on save; the React app writes back to them
        // whenever the editor state changes so Craft picks up the values
        // without needing a custom form hook.
        Craft::$app->getView()->registerAssetBundle(CodeComponentFieldAsset::class);

        $namespacedName = Craft::$app->getView()->namespaceInputName($this->handle ?? 'codeComponent');
        $namespacedId = Craft::$app->getView()->namespaceInputId($this->getInputId());

        $bootstrap = [
            'inputId' => $namespacedId,
            'fieldId' => (int) ($this->id ?? 0),
            'fieldHandle' => $this->handle,
            'fieldName' => (string) ($this->name ?? $this->handle),
            'inputName' => $namespacedName,
            'permissions' => $permissions,
            'values' => [
                'twig' => $value->twig,
                'css' => $value->css,
                'js' => $value->js,
                'agentSessionId' => $value->agentSessionId,
            ],
            'element' => self::summarizeElement($element),
            'chat' => self::chatBootstrap(),
            'persist' => self::persistBootstrap(),
        ];

        return Html::tag(
            'div',
            // The script block carries the bootstrap payload as JSON so the
            // React entry can read it after asset publication. The hidden
            // inputs are written *after* the script so the React mount can
            // find them as siblings of the root div during init.
            Html::tag('script', Json::htmlEncode($bootstrap), [
                'type' => 'application/json',
                'data-craftai-code-component-bootstrap' => true,
            ]).
            self::hiddenInput("{$namespacedName}[twig]", $value->twig).
            self::hiddenInput("{$namespacedName}[css]", $value->css).
            self::hiddenInput("{$namespacedName}[js]", $value->js).
            self::hiddenInput("{$namespacedName}[agentSessionId]", $value->agentSessionId ?? ''),
            [
                'id' => $namespacedId,
                'class' => 'craftai-code-component-root',
                'data-craftai-code-component-root' => true,
            ],
        );
    }

    private static function hiddenInput(string $name, string $value): string
    {
        return Html::tag('input', '', [
            'type' => 'hidden',
            'name' => $name,
            'value' => $value,
            'data-craftai-code-component-input' => self::trailingKeyOf($name),
        ]);
    }

    /**
     * `myField[twig]` → `twig`. Used so the React app can `querySelector` the
     * right hidden input by purpose-tag without parsing the bracketed name.
     */
    private static function trailingKeyOf(string $name): string
    {
        if (preg_match('/\[(twig|css|js|agentSessionId)\]$/', $name, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * Compact element snapshot for the chat's page-context payload. Keeps
     * just enough to let the agent describe what entry it's editing — and
     * crucially, which identifier the `update_code_component` tool should
     * see — without shipping the whole element tree.
     *
     * Drafts (including the provisional drafts Craft auto-creates around
     * matrix blocks in the CP editor) round-trip both their `draftId` and
     * `canonicalId` so the agent can pick the right tool argument. Matrix
     * blocks additionally expose their `ownerId` so the agent has the
     * parent entry id when it needs to reason about the surrounding page.
     *
     * @return array{type: string, id: ?int, title: ?string, sectionHandle: ?string, isDraft: bool, isProvisionalDraft: bool, draftId: ?int, canonicalId: ?int, ownerId: ?int}|null
     */
    private static function summarizeElement(?ElementInterface $element): ?array
    {
        if ($element === null) {
            return null;
        }

        $type = $element::refHandle();
        if (! is_string($type) || $type === '') {
            $type = strtolower((new \ReflectionClass($element))->getShortName());
        }

        // Element::getUiLabel() is part of ElementInterface, but the type
        // hint is mixed so we still need a string-cast + empty check before
        // falling back to the element's title.
        $title = (string) $element->getUiLabel();
        if ($title === '') {
            $rawTitle = $element->title ?? null;
            $title = is_string($rawTitle) ? $rawTitle : '';
        }

        $sectionHandle = null;
        $ownerId = null;
        if ($element instanceof \craft\elements\Entry) {
            try {
                $sectionHandle = $element->getSection()?->handle;
            } catch (\Throwable) {
                // Nested entries without a section — leave null.
            }
            $rawOwnerId = $element->primaryOwnerId ?? null;
            if (is_int($rawOwnerId) && $rawOwnerId > 0) {
                $ownerId = $rawOwnerId;
            }
        }

        $isDraft = $element->getIsDraft();
        $isProvisional = $isDraft && (bool) $element->isProvisionalDraft;

        // For a draft, `getCanonicalId()` falls back to the element's own id
        // when there's no canonical yet (fresh draft). Normalize that case
        // to null so the agent can tell "draft of #123" from "fresh draft".
        $canonicalId = null;
        if ($isDraft) {
            $rawCanonicalId = $element->getCanonicalId();
            if (is_int($rawCanonicalId) && $rawCanonicalId !== $element->id) {
                $canonicalId = $rawCanonicalId;
            }
        }

        $draftId = null;
        if ($isDraft) {
            $rawDraftId = $element->draftId ?? null;
            if (is_int($rawDraftId) && $rawDraftId > 0) {
                $draftId = $rawDraftId;
            }
        }

        return [
            'type' => $type,
            'id' => $element->id !== null ? (int) $element->id : null,
            'title' => $title !== '' ? $title : null,
            'sectionHandle' => $sectionHandle,
            'isDraft' => $isDraft,
            'isProvisionalDraft' => $isProvisional,
            'draftId' => $draftId,
            'canonicalId' => $canonicalId,
            'ownerId' => $ownerId,
        ];
    }

    /**
     * URLs the React editor uses to sync field state out-of-band with the
     * surrounding entry-edit form. `stateUrl` polls the persisted tab
     * values so agent writes show up live; `persistSessionUrl` writes a
     * newly minted chat session id to disk immediately so a thread never
     * gets orphaned by a navigation that pre-empts autosave.
     *
     * @return array<string, string>
     */
    private static function persistBootstrap(): array
    {
        return [
            'stateUrl' => UrlHelper::actionUrl('craft-ai/code-component/state'),
            'persistSessionUrl' => UrlHelper::actionUrl('craft-ai/code-component/persist-session'),
        ];
    }

    /**
     * URLs the embedded Chat needs. The CSRF token comes from the page's
     * Craft.csrfTokenValue global, so it's not duplicated here.
     *
     * @return array<string, string>
     */
    private static function chatBootstrap(): array
    {
        return [
            'messagesUrl' => UrlHelper::actionUrl('craft-ai/messages'),
            'sendUrl' => UrlHelper::actionUrl('craft-ai/sessions/send'),
            'sessionsUrl' => UrlHelper::actionUrl('craft-ai/sessions/data'),
            'newSessionUrl' => UrlHelper::actionUrl('craft-ai/sessions/new'),
            'sessionsIndexUrl' => UrlHelper::cpUrl('ai/sessions'),
            'previewRespondUrl' => UrlHelper::actionUrl('craft-ai/preview/respond'),
            'toolModeUrl' => UrlHelper::actionUrl('craft-ai/sessions/tool-mode'),
            'updateToolModeUrl' => UrlHelper::actionUrl('craft-ai/sessions/update-tool-mode'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function stringFrom(array $value, string $key): string
    {
        $raw = $value[$key] ?? '';

        return is_string($raw) ? $raw : '';
    }
}
