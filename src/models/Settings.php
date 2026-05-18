<?php

namespace markhuot\craftai\models;

use craft\base\Model;

/**
 * Plugin settings model. Used by Craft to render the CP settings form and
 * to persist user-managed configuration.
 *
 * This is intentionally narrow: it only owns the user-managed pieces that
 * make sense to edit in a CP form. Provider, API key, model name, and
 * other LLM-tier knobs stay in `config/craft-ai.php` (read via
 * {@see \markhuot\craftai\Plugin::getSettingsArray()}). Mixing the two
 * storage paths would mean dev/prod could diverge silently — the file
 * config doubles as the contract for "what the LLM is configured with",
 * and we don't want a CP edit to override what the file declares.
 *
 * The fields owned by this model (automations, commands) flow through
 * Craft's plugin-settings → project-config pipeline automatically:
 * {@see \craft\services\Plugins::savePluginSettings()} writes
 * `$this->toArray()` into `plugins.craft-ai.settings` in project config,
 * and the corresponding project-config change handler re-applies the
 * data on other environments. Both fields are plain typed `public`
 * properties so Yii's attribute machinery includes them in `toArray()`
 * without further wiring.
 */
class Settings extends Model
{
    /**
     * Raw automation rows. Stored as plain arrays here (rather than as
     * {@see Automation} instances) so Craft's JSON-encoded
     * `plugins.settings` column round-trips cleanly without a custom
     * serializer. Use {@see getAutomations()} to materialize them.
     *
     * @var list<array<string, mixed>>
     */
    public array $automations = [];

    /**
     * Raw command rows. Nullable so the model can distinguish "never
     * configured" (return defaults) from "explicitly cleared" (return
     * empty). Yii's attribute machinery only invokes {@see setCommands()}
     * when the project config / form post supplies a value for the key —
     * if the key is absent, the property stays null and {@see getCommands()}
     * substitutes in the seeded defaults.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $commands = null;

    /**
     * @return list<Automation>
     */
    public function getAutomations(): array
    {
        $models = [];
        foreach ($this->automations as $row) {
            $models[] = Automation::fromArray($row);
        }
        return $models;
    }

    /**
     * Accepts either a list of arrays (from a Craft form post) or a list
     * of Automation models (from programmatic setup) and stores them as
     * plain arrays internally. Drops rows with empty prompts so the
     * "add row" button can leave blank stubs without dispatching against
     * an empty automation.
     *
     * @param list<array<string, mixed>|Automation>|array<string|int, array<string, mixed>|Automation> $value
     */
    public function setAutomations(array $value): void
    {
        $normalized = [];
        foreach ($value as $row) {
            if ($row instanceof Automation) {
                $row = $row->toConfigArray();
            }

            $prompt = $row['prompt'] ?? '';
            $event = $row['event'] ?? '';
            if (! is_string($prompt) || trim($prompt) === '') continue;
            if (! is_string($event) || $event === '') continue;

            $normalized[] = Automation::fromArray($row)->toConfigArray();
        }
        $this->automations = $normalized;
    }

    /**
     * Resolve the list of slash commands. When the persisted list is
     * `null` (never configured), substitute the seeded defaults so a
     * fresh install has `/translate` and `/editorial-review` available
     * out of the box. An explicitly empty list (the editor cleared the
     * table and saved) is returned as-is.
     *
     * @return list<Command>
     */
    public function getCommands(): array
    {
        $rows = $this->commands ?? Command::defaults();
        $models = [];
        foreach ($rows as $row) {
            $models[] = Command::fromArray($row);
        }
        return $models;
    }

    /**
     * Accepts either a list of arrays (from a Craft form post) or a list
     * of Command models (from programmatic setup) and stores them as
     * plain arrays internally. Drops rows with empty names or prompts so
     * the "add row" button can leave blank stubs without producing a
     * dead `/` entry in the autocomplete.
     *
     * Once setCommands runs at all — even with an empty array — the
     * property leaves its null sentinel state. {@see getCommands()} then
     * returns whatever's persisted instead of falling back to defaults,
     * so an editor who cleared the table doesn't see the defaults
     * resurrect on the next request.
     *
     * @param list<array<string, mixed>|Command>|array<string|int, array<string, mixed>|Command>|null $value
     */
    public function setCommands(?array $value): void
    {
        // Null preserves the "never configured" sentinel so getCommands()
        // can fall back to the seeded defaults. We treat it as distinct
        // from `[]` (explicitly cleared) — see the property docblock.
        if ($value === null) {
            $this->commands = null;
            return;
        }

        $normalized = [];
        $seen = [];
        foreach ($value as $row) {
            if ($row instanceof Command) {
                $row = $row->toConfigArray();
            }

            // The fromArray() coercion lowercases / strips invalid chars
            // out of the user-entered name, so we filter on the
            // normalized value rather than the raw post.
            $cmd = Command::fromArray($row);
            if ($cmd->name === '') continue;
            if (trim($cmd->prompt) === '') continue;

            // Drop duplicate names rather than letting Yii's validator
            // complain after-the-fact — the dispatcher's first-match
            // semantics would silently hide the second entry anyway.
            if (isset($seen[$cmd->name])) continue;
            $seen[$cmd->name] = true;

            $normalized[] = $cmd->toConfigArray();
        }
        $this->commands = $normalized;
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            ['automations', 'validateAutomations'],
            ['commands', 'validateCommands'],
        ];
    }

    public function validateAutomations(string $attribute): void
    {
        foreach ($this->getAutomations() as $i => $auto) {
            if (! $auto->validate()) {
                foreach ($auto->getFirstErrors() as $field => $msg) {
                    $this->addError($attribute, "Row {$i} ({$field}): {$msg}");
                }
            }
        }
    }

    public function validateCommands(string $attribute): void
    {
        foreach ($this->getCommands() as $i => $cmd) {
            if (! $cmd->validate()) {
                foreach ($cmd->getFirstErrors() as $field => $msg) {
                    $this->addError($attribute, "Row {$i} ({$field}): {$msg}");
                }
            }
        }
    }
}
