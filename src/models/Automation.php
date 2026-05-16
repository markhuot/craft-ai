<?php

namespace markhuot\craftai\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * A single user-defined automation rule.
 *
 * Each rule pairs a Craft event (`entry.saved`, `draft.saved`,
 * `draft.applied`, `entry.deleted`, `asset.saved`) with a free-form
 * prompt that fires a new agent session when the event matches. The
 * optional `sectionHandle` narrows the match to one section so a "review
 * every saved draft" rule doesn't fire across the whole site.
 *
 * Rules live in the plugin's settings (which Craft round-trips through
 * the `plugins.settings` DB column, and through project config when the
 * host site has project config enabled), so they're version-controllable
 * the same way other plugin settings are.
 */
class Automation extends Model
{
    public const EVENT_ENTRY_SAVED = 'entry.saved';
    public const EVENT_DRAFT_SAVED = 'draft.saved';
    public const EVENT_DRAFT_APPLIED = 'draft.applied';
    public const EVENT_ENTRY_DELETED = 'entry.deleted';
    public const EVENT_ASSET_SAVED = 'asset.saved';

    /**
     * Canonical list of supported events with human labels for the
     * settings dropdown. Keep the keys aligned with the EVENT_*
     * constants so the dispatcher can pivot on them.
     *
     * @return array<string, string>
     */
    public static function eventChoices(): array
    {
        return [
            self::EVENT_ENTRY_SAVED => 'Entry saved (canonical, not draft)',
            self::EVENT_DRAFT_SAVED => 'Draft saved',
            self::EVENT_DRAFT_APPLIED => 'Draft applied (published)',
            self::EVENT_ENTRY_DELETED => 'Entry deleted',
            self::EVENT_ASSET_SAVED => 'Asset saved',
        ];
    }

    /**
     * Stable identifier for this rule. Persisted so the settings UI can
     * preserve ordering and the dispatcher can log which rule fired.
     * Generated on first save if the caller didn't supply one.
     */
    public string $uid = '';

    /** Optional human label. Surfaces in session titles and logs. */
    public string $name = '';

    public string $event = self::EVENT_DRAFT_SAVED;

    /** Empty string means "any section". Asset/entry-delete rules ignore this. */
    public string $sectionHandle = '';

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
            [['event', 'prompt'], 'required'],
            [['event'], 'in', 'range' => array_keys(self::eventChoices())],
            [['name', 'sectionHandle'], 'string', 'max' => 255],
            [['prompt'], 'string', 'max' => 4000],
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
            'event' => $this->event,
            'sectionHandle' => $this->sectionHandle,
            'prompt' => $this->prompt,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Inflate from a raw associative array, tolerating missing keys so
     * older settings rows (pre-feature) and partial POSTs both work.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $auto = new self();
        $auto->uid = is_string($data['uid'] ?? null) && $data['uid'] !== '' ? $data['uid'] : StringHelper::UUID();
        $auto->name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $auto->event = is_string($data['event'] ?? null) ? $data['event'] : self::EVENT_DRAFT_SAVED;
        $auto->sectionHandle = is_string($data['sectionHandle'] ?? null) ? $data['sectionHandle'] : '';
        $auto->prompt = is_string($data['prompt'] ?? null) ? $data['prompt'] : '';
        // Craft form posts deliver booleans as "1"/"0" or "on"/"" depending
        // on the input. Normalize to a real bool so the rules validator and
        // the dispatcher both see the right type.
        $rawEnabled = $data['enabled'] ?? true;
        $auto->enabled = filter_var($rawEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        return $auto;
    }
}
