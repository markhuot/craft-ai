<?php

namespace markhuot\craftai\diff;

use craft\behaviors\RevisionBehavior;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;

/**
 * Resolves a string "version ref" to a concrete {@see Entry} for diffing.
 *
 * Accepted refs:
 *   - "current" / "canonical" → the live canonical entry
 *   - "rev:<revisionId>"      → a specific revision
 *   - "draft:<draftId>"       → a specific draft
 *   - "<int>"                 → shorthand for a revisionId (revisions are the
 *                               common case; drafts must be prefixed explicitly
 *                               because revisionId and draftId share an integer
 *                               namespace, so a bare int can't safely mean both)
 *
 * Every lookup is scoped to the given canonical id — a revision/draft of some
 * other entry resolves to null — and optionally to a site, so both sides of a
 * diff are read on the same locale.
 */
final class VersionRef
{
    public static function resolve(int $canonicalId, string $ref, ?int $siteId = null): ?Entry
    {
        $ref = trim($ref);

        if ($ref === '' || $ref === 'current' || $ref === 'canonical') {
            return self::one(self::base($siteId)->id($canonicalId));
        }
        if (preg_match('/^rev:(\d+)$/', $ref, $m) === 1) {
            return self::ofCanonical(self::one(self::base($siteId)->revisions(true)->id((int) $m[1])), $canonicalId);
        }
        if (preg_match('/^draft:(\d+)$/', $ref, $m) === 1) {
            return self::ofCanonical(self::one(self::base($siteId)->draftId((int) $m[1])), $canonicalId);
        }
        if (ctype_digit($ref)) {
            return self::ofCanonical(self::one(self::base($siteId)->revisions(true)->id((int) $ref)), $canonicalId);
        }

        return null;
    }

    /**
     * True when the ref is syntactically valid (does not guarantee the target
     * exists — use {@see resolve()} for that).
     */
    public static function isValid(string $ref): bool
    {
        $ref = trim($ref);

        return $ref === 'current'
            || $ref === 'canonical'
            || ctype_digit($ref)
            || preg_match('/^(rev|draft):\d+$/', $ref) === 1;
    }

    /**
     * Short human label for a resolved version, e.g. "Current", "Revision 7",
     * or "Draft".
     */
    public static function label(Entry $entry): string
    {
        if ($entry->getIsRevision()) {
            $behavior = self::revisionBehavior($entry);

            return $behavior !== null ? "Revision {$behavior->revisionNum}" : 'Revision';
        }
        if ($entry->getIsDraft()) {
            return 'Draft';
        }

        return 'Current';
    }

    /**
     * The canonical ref string for a resolved version — the inverse of
     * {@see resolve()}. Used when building picker options.
     */
    public static function refFor(Entry $entry): string
    {
        // Revisions are addressed by their element id (the value
        // craft\services\Revisions::createRevision() returns and that
        // GetRevisions lists), drafts by their draftId.
        if ($entry->getIsRevision() && $entry->id !== null) {
            return 'rev:'.$entry->id;
        }
        if ($entry->getIsDraft() && $entry->draftId !== null) {
            return 'draft:'.$entry->draftId;
        }

        return 'current';
    }

    /**
     * Locate the RevisionBehavior on an element without depending on the exact
     * attach key, so {@see revisionNum} etc. are reachable in a way PHPStan can
     * type.
     */
    public static function revisionBehavior(Entry $entry): ?RevisionBehavior
    {
        foreach ($entry->getBehaviors() as $behavior) {
            if ($behavior instanceof RevisionBehavior) {
                return $behavior;
            }
        }

        return null;
    }

    /**
     * @return EntryQuery<int, Entry>
     */
    private static function base(?int $siteId): EntryQuery
    {
        $query = Entry::find()->status(null);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $query;
    }

    /**
     * @param  EntryQuery<int, Entry>  $query
     */
    private static function one(EntryQuery $query): ?Entry
    {
        $entry = $query->one();

        return $entry instanceof Entry ? $entry : null;
    }

    private static function ofCanonical(?Entry $entry, int $canonicalId): ?Entry
    {
        if ($entry === null) {
            return null;
        }

        return ($entry->getCanonicalId() === $canonicalId || $entry->id === $canonicalId)
            ? $entry
            : null;
    }
}
