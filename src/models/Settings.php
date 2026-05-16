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
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            ['automations', 'validateAutomations'],
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
}
