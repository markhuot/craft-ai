<?php

namespace markhuot\craftai\helpers;

use craft\elements\Entry;

/**
 * Resolve which element IDs are "in scope" for a comment listing call.
 *
 * A page entry's comment scope includes the entry itself plus every
 * nested entry beneath it — Matrix blocks (and nested-matrix blocks) are
 * first-class entries in Craft 5, so feedback left on a block field
 * targets the block's own elementId. The CP overlay (and the agent's
 * `get_comments`) want one query that covers the whole tree so editors
 * see every dot at once.
 *
 * Drafts: when the page is a draft, we walk from the draft entry and
 * surface descendants by their own draftIds where present, falling back
 * to the canonical id otherwise. We also bridge across the
 * draft/canonical boundary in *both* directions — viewing a canonical
 * sees comments left on any of its drafts, viewing a draft sees
 * comments left on the canonical (or on sibling drafts). Without that
 * bridge, a comment authored on a draft disappears the moment the draft
 * is applied, and a comment authored on a canonical is invisible while
 * the editor is working in a draft.
 *
 * The controller still filters records by the literal (elementId,
 * isDraft) pair stamped on each row, so every element-id only matches
 * the comments authored against that exact identity — we just feed in
 * a wider list of identities.
 */
final class CommentScope
{
    /**
     * Collect the (elementId, isDraft) pairs that the page element
     * covers, including itself.
     *
     * @return list<array{0: int, 1: bool}>
     */
    public static function pairsFor(int $elementId, bool $isDraft): array
    {
        $root = self::loadRoot($elementId, $isDraft);

        $pairs = [[$elementId, $isDraft]];
        if ($root === null) {
            return $pairs;
        }

        $visited = [self::keyFor($elementId, $isDraft) => true];
        $queue = [$root];

        // Seed the queue with every "sibling identity" of the root —
        // its canonical (when the root is a draft) and every draft of
        // its canonical (when the root is a canonical). All siblings
        // share the same Matrix-block subtree on the canonical side
        // but may have draft-side copies too, so we still walk each
        // one's owned children below.
        foreach (self::siblingRoots($root) as $sibling) {
            $siblingId = $sibling->draftId !== null
                ? (int) $sibling->draftId
                : (int) $sibling->id;
            $siblingIsDraft = $sibling->draftId !== null;
            $key = self::keyFor($siblingId, $siblingIsDraft);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            $pairs[] = [$siblingId, $siblingIsDraft];
            $queue[] = $sibling;
        }

        while ($queue !== []) {
            $current = array_shift($queue);

            // ownerId() resolves the canonical owner relation Craft
            // tracks for Matrix-nested entries. It picks up both
            // canonical-side blocks and the draft-side variants Craft
            // duplicates when a parent draft is edited.
            $children = Entry::find()
                ->ownerId((int) $current->id)
                ->drafts(null)
                ->status(null)
                ->all();

            foreach ($children as $child) {
                $childId = $child->draftId !== null
                    ? (int) $child->draftId
                    : (int) $child->id;
                $childIsDraft = $child->draftId !== null;

                $key = self::keyFor($childId, $childIsDraft);
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $pairs[] = [$childId, $childIsDraft];
                $queue[] = $child;
            }
        }

        return $pairs;
    }

    /**
     * Return the canonical / draft "siblings" of a given root.
     *
     *  - Canonical root → returns every draft of it (so canonical-view
     *    sees draft-only comments after a draft is applied or while
     *    a parallel draft is in flight).
     *  - Draft root → returns the canonical (so draft-view sees
     *    canonical-anchored comments left before the draft existed).
     *
     * Provisional drafts are deliberately included — Craft auto-creates
     * one when an editor opens an entry, and a comment left during
     * that session is filed against the provisional draftId. Without
     * including them the next person to open the entry would see an
     * empty overlay.
     *
     * @return list<Entry>
     */
    private static function siblingRoots(Entry $root): array
    {
        if ($root->draftId !== null) {
            $canonicalId = (int) $root->canonicalId;
            if ($canonicalId <= 0) {
                return [];
            }
            $canonical = Entry::find()->id($canonicalId)->status(null)->one();
            return $canonical instanceof Entry ? [$canonical] : [];
        }

        // Canonical root — find all drafts (including provisionals).
        /** @var list<Entry> $drafts */
        $drafts = Entry::find()
            ->draftOf($root)
            ->status(null)
            ->provisionalDrafts(null)
            ->all();
        return $drafts;
    }

    /**
     * Convenience flatten: just the IDs, ignoring isDraft. Matches the
     * old single-element query shape so callers that don't care about
     * draft/canonical disambiguation can stay simple.
     *
     * @return list<int>
     */
    public static function idsFor(int $elementId, bool $isDraft): array
    {
        return array_map(
            static fn (array $pair): int => $pair[0],
            self::pairsFor($elementId, $isDraft),
        );
    }

    private static function loadRoot(int $elementId, bool $isDraft): ?Entry
    {
        $query = Entry::find()->status(null);

        if ($isDraft) {
            $query->draftId($elementId);
        } else {
            $query->id($elementId);
        }

        $entry = $query->one();

        return $entry instanceof Entry ? $entry : null;
    }

    private static function keyFor(int $id, bool $isDraft): string
    {
        return ($isDraft ? 'd:' : 'e:').$id;
    }
}
