<?php

namespace markhuot\craftai\fields;

use craft\elements\User;

/**
 * Permission identifiers for the four authoring tabs of {@see CodeComponent}.
 * Each tab is independently gated; admins implicitly hold every permission.
 *
 * The same identifiers gate both the control-panel UI (which tabs render
 * on the entry-edit page) and the agent's writeback tool (which fields it
 * is allowed to update on the user's behalf). Drafts remain the rollback
 * mechanism — there is no per-edit accept/reject flow.
 */
class CodeComponentPermissions
{
    public const TWIG = 'craftAi:codeComponent:editTwig';

    public const CSS = 'craftAi:codeComponent:editCss';

    public const JS = 'craftAi:codeComponent:editJs';

    public const PROMPT = 'craftAi:codeComponent:usePrompt';

    /**
     * Resolve which tabs the given user can edit. Returns a stable shape so
     * callers (field UI, agent tool) don't have to repeat the same branchy
     * `checkPermission` calls. A null identity (guest / unauthenticated CLI
     * call) gets no access; only an admin or a user holding each permission
     * passes that tab.
     *
     * @return array{twig: bool, css: bool, js: bool, prompt: bool}
     */
    public static function resolve(?User $user): array
    {
        if ($user === null) {
            return ['twig' => false, 'css' => false, 'js' => false, 'prompt' => false];
        }

        if ($user->admin) {
            return ['twig' => true, 'css' => true, 'js' => true, 'prompt' => true];
        }

        return [
            'twig' => $user->can(self::TWIG),
            'css' => $user->can(self::CSS),
            'js' => $user->can(self::JS),
            'prompt' => $user->can(self::PROMPT),
        ];
    }

    /**
     * @return list<array{key: string, label: string, info: string}>
     */
    public static function definitions(): array
    {
        return [
            ['key' => self::TWIG, 'label' => 'Edit the Twig tab of Code Component fields', 'info' => 'Required to view or edit the Twig tab on a Code Component field. Twig runs against the full Craft template context, so grant this carefully.'],
            ['key' => self::CSS, 'label' => 'Edit the CSS tab of Code Component fields', 'info' => 'Required to view or edit the CSS tab on a Code Component field.'],
            ['key' => self::JS, 'label' => 'Edit the JS tab of Code Component fields', 'info' => 'Required to view or edit the JavaScript tab on a Code Component field.'],
            ['key' => self::PROMPT, 'label' => 'Use the agent Prompt tab on Code Component fields', 'info' => 'Required to open the agent chat on a Code Component field. Permission to actually modify the Twig/CSS/JS tabs is still gated independently.'],
        ];
    }
}
