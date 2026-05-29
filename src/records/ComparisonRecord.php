<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * Memoizes a revision comparison so reopening the same A/B pair reuses its
 * narration session and rendered artifact instead of minting a fresh session
 * and burning tokens re-narrating an identical diff.
 *
 * Keyed by (entryId, siteId, aRef, bRef). `fingerprint` captures the resolved
 * *content* identity of both sides (each side's ref + dateUpdated): two
 * immutable revisions yield a constant fingerprint — a permanent cache hit —
 * while a pair involving a mutable side ("current" or a draft) misses the
 * cache once that side is actually edited, forcing exactly one re-narration.
 *
 * The memoized session is created with `userId = null` (see
 * {@see \markhuot\craftai\controllers\CompareController}) so any editor who
 * opens the same comparison reuses it.
 *
 * @property int $id
 * @property int $entryId Canonical entry being compared
 * @property int $siteId Site/locale both sides were read on
 * @property string $aRef Version ref for side A (e.g. "current", "rev:7")
 * @property string $bRef Version ref for side B
 * @property string $fingerprint Resolved content identity of A+B; the recompute key
 * @property string $sessionId Narration session memoized for this comparison
 * @property int|null $artifactId Rendered diff artifact for this comparison
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ComparisonRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_comparisons}}';
    }
}
