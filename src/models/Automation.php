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
     * Which container an event scopes against. Entry-shaped events filter
     * by section handle; asset-shaped events filter by volume handle. The
     * settings UI swaps its scope picker based on this, and the dispatcher
     * uses it to decide which handle to consult.
     *
     * Returns `null` for events with no meaningful scope (none today, but
     * leaves room for future events like `user.saved` that wouldn't fit
     * either category).
     */
    public static function scopeFor(string $event): ?string
    {
        return match ($event) {
            self::EVENT_ENTRY_SAVED,
            self::EVENT_DRAFT_SAVED,
            self::EVENT_DRAFT_APPLIED,
            self::EVENT_ENTRY_DELETED => 'section',
            self::EVENT_ASSET_SAVED => 'volume',
            default => null,
        };
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

    /** Empty string means "any section". Only consulted for entry-shaped events; ignored otherwise. */
    public string $sectionHandle = '';

    /** Empty string means "any volume". Only consulted for asset-shaped events; ignored otherwise. */
    public string $volumeHandle = '';

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
            [['name', 'sectionHandle', 'volumeHandle'], 'string', 'max' => 255],
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
            'volumeHandle' => $this->volumeHandle,
            'prompt' => $this->prompt,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Inflate from a raw associative array, tolerating missing keys so
     * older settings rows (pre-feature) and partial POSTs both work.
     *
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $auto = new self();
        $auto->uid = is_string($data['uid'] ?? null) && $data['uid'] !== '' ? $data['uid'] : StringHelper::UUID();
        $auto->name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $auto->event = is_string($data['event'] ?? null) ? $data['event'] : self::EVENT_DRAFT_SAVED;
        $auto->sectionHandle = is_string($data['sectionHandle'] ?? null) ? $data['sectionHandle'] : '';
        $auto->volumeHandle = is_string($data['volumeHandle'] ?? null) ? $data['volumeHandle'] : '';
        $auto->prompt = is_string($data['prompt'] ?? null) ? $data['prompt'] : '';
        // Craft form posts deliver booleans as "1"/"0" or "on"/"" depending
        // on the input. Normalize to a real bool so the rules validator and
        // the dispatcher both see the right type.
        $rawEnabled = $data['enabled'] ?? true;
        $auto->enabled = filter_var($rawEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        return $auto;
    }
}
