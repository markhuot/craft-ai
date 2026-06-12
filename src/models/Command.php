<?php

namespace markhuot\craftai\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * A user-defined slash command.
 *
 * Each command pairs a slug-safe `name` (used at the prompt as `/name`)
 * with a `prompt` that gets substituted into the conversation when the
 * editor invokes it. Commands live in plugin settings — and therefore in
 * project config — so an org can version-control the prompts the same way
 * it version-controls other configuration.
 *
 * The plugin ships with three seeded commands (`translate`,
 * `editorial-review`, `compare`), defined in {@see self::defaults()}. They
 * appear pre-populated on first settings load and can be edited or deleted
 * like any other row.
 */
class Command extends Model
{
    /**
     * Slug-safe command name regex. Mirrors the character set Craft uses
     * for handles (lowercase letters, digits, dashes) so the value round-
     * trips through URLs, YAML, and the prompt input without escaping
     * surprises.
     */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * Reserved names that collide with built-in slash commands. The
     * dispatcher would route a built-in before a user command anyway,
     * but we reject the name at validate time so an editor doesn't sink
     * time into a `/compact` override that silently never fires.
     */
    private const RESERVED_NAMES = ['compact', 'review'];

    /**
     * Stable identifier for this command. Persisted so the settings UI
     * can preserve ordering across saves.
     */
    public string $uid = '';

    /** Slug-safe name. Surfaces in the prompt input as `/name`. */
    public string $name = '';

    /**
     * Instruction substituted in for the user's `/name` message when the
     * command is invoked. Supports a `{args}` placeholder; if absent and
     * the user typed args after the slash command, they get appended on
     * a trailing line.
     */
    public string $prompt = '';

    public bool $enabled = true;

    public function init(): void
    {
        parent::init();

        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'prompt'], 'required'],
            [['name'], 'match', 'pattern' => self::NAME_PATTERN, 'message' => 'Name must contain only lowercase letters, digits, and dashes (no spaces).'],
            [['name'], 'string', 'max' => 64],
            [['name'], function (string $attribute): void {
                if (in_array($this->$attribute, self::RESERVED_NAMES, true)) {
                    $this->addError($attribute, "“{$this->$attribute}” is a built-in slash command. Pick a different name.");
                }
            }],
            [['prompt'], 'string', 'max' => 8000],
            [['enabled'], 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'uid' => $this->uid,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Inflate from a raw associative array. Tolerates missing keys so a
     * partial CP form post (the "+" stub row) doesn't blow up before the
     * Settings filter has a chance to drop it.
     *
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cmd = new self();
        $cmd->uid = is_string($data['uid'] ?? null) && $data['uid'] !== '' ? $data['uid'] : StringHelper::UUID();
        $cmd->name = is_string($data['name'] ?? null) ? self::normalizeName($data['name']) : '';
        $cmd->prompt = is_string($data['prompt'] ?? null) ? $data['prompt'] : '';
        $rawEnabled = $data['enabled'] ?? true;
        $cmd->enabled = filter_var($rawEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        return $cmd;
    }

    /**
     * Coerce a user-entered name into the slug-safe shape we persist:
     * lowercase, ASCII, dashes for whitespace/underscores, no leading
     * dash. We do this on read rather than reject so a CP user who types
     * "Editorial Review" gets `editorial-review` instead of a hard
     * validation failure they have to clean up themselves.
     */
    public static function normalizeName(string $raw): string
    {
        $name = strtolower(trim($raw));
        $name = preg_replace('/[\s_]+/', '-', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9-]/', '', $name) ?? $name;
        $name = preg_replace('/-+/', '-', $name) ?? $name;
        return trim($name, '-');
    }

    /**
     * Default commands seeded into a fresh install. Surfaced when the
     * persisted list is empty (see {@see Settings::getCommands()}) so a
     * new install always shows editors a useful starting point — and so
     * the rules they trigger on stay version-controlled once they save.
     *
     * Editors can delete or rename either default; once the persisted
     * list contains *anything* (even just one custom row), the defaults
     * stop appearing. This sidesteps the "delete the default, see it
     * reappear on next request" loop.
     *
     * The UIDs are hardcoded rather than minted on each {@see fromArray}
     * call. Why: the dedicated edit screen routes by UID
     * (`ai/commands/<uid>`), so the link rendered on the settings page
     * and the lookup performed by the edit controller need to agree on
     * the same identifier across requests. A user who hasn't saved
     * anything yet still sees the seeded rows; without stable UIDs, each
     * page render would mint fresh ones and clicking through would 404.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'uid' => 'craft-ai--default-translate',
                'name' => 'translate',
                'prompt' => <<<'PROMPT'
                Translate the current entry's content into the language the user names in their message (default: Spanish if no language is given).

                Sites are the locale boundary in Craft — every site has exactly one `language` (e.g. "en-US", "es-MX", "fr") and content fields store one value per site. Use `get_sites` to discover which locales already exist. If a site exists for the requested language, save the translation against it by calling `upsert_draft` (preferred) or `upsert_entry` with that site's handle as the `site` argument. If no site exists for the target language, create one with `upsert_site` first, then translate into it.

                Preserve formatting, links, and inline references. Produce a draft of the translated entry rather than overwriting the original.
                PROMPT,
                'enabled' => true,
            ],
            [
                'uid' => 'craft-ai--default-editorial-review',
                'name' => 'editorial-review',
                'prompt' => <<<'PROMPT'
                Perform an editorial review of the current entry. Evaluate clarity, structure, tone, and factual consistency. Flag passive voice that obscures the actor, awkward phrasing, repeated words, and missing context. Suggest concrete rewrites — not just observations — and leave inline comments on the specific fields that need attention.
                PROMPT,
                'enabled' => true,
            ],
            [
                'uid' => 'craft-ai--default-compare',
                'name' => 'compare',
                'prompt' => <<<'PROMPT'
                Compare two versions of the current entry and explain what changed and why it matters.

                The user names the two versions in {args} — e.g. "rev:120 rev:119", "rev:120 current", or just two revision ids like "120 119". Treat the first as version A and the second as version B; default B to "current" if only one is given.

                Use `get_revisions` to discover which revisions exist if you need to, then call `diff_revisions` with the entry's id and the two refs to get a deterministic, field-by-field diff. Narrate the editorially-significant changes grouped by field, leading with what matters most. Finally call `render_artifact` to save the rendered diff as an artifact, then `open_artifact` with the returned artifactId to show it in the preview pane.
                PROMPT,
                'enabled' => true,
            ],
        ];
    }
}
