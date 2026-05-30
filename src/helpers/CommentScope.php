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
 * Drafts are scoped *strictly*: a canonical entry surfaces only the
 * comments anchored to the canonical and its canonical block subtree,
 * and a draft surfaces only the comments anchored to that draft and its
 * own block subtree. We deliberately do NOT bridge across the
 * draft/canonical boundary — comments left on a draft are about that
 * draft's in-flight content and must not leak onto the live entry (or
 * onto a sibling draft), and vice versa. Each working copy owns a
 * separate comment thread.
 *
 * This isolation falls out of Craft's ownership model for free: nested
 * entries are owned (via `elements_owners`) by a specific owner element
 * id — canonical blocks by the canonical, draft blocks by the draft
 * element — so walking `ownerId($current->id)` down from the page root
 * can only ever reach that page's own subtree. The single place the old
 * implementation crossed the boundary was a "sibling roots" seed that
 * folded a canonical's drafts (and a draft's canonical) into the query;
 * that seed is gone.
 *
 * Note: a comment authored on a draft is stamped with that draftId, so
 * once the draft is applied (and the draftId ceases to exist) the
 * comment no longer resolves to any visible element. That's an accepted
 * trade-off of strict scoping — open draft feedback is not graduated to
 * the canonical on apply.
 *
 * The controller still filters records by the literal (elementId,
 * isDraft) pair stamped on each row, so every element-id only matches
 * the comments authored against that exact identity — we just feed in
 * the list of identities for the page's own tree.
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

        while ($queue !== []) {
            $current = array_shift($queue);

            // ownerId() resolves the owner relation Craft tracks for
            // Matrix-nested entries. Because a draft's blocks are owned
            // by the draft element (not the canonical), walking down from
            // the page root stays on the page's own side of the
            // draft/canonical boundary: a canonical root only reaches
            // canonical blocks, a draft root only reaches that draft's
            // blocks. drafts(null) is needed so the draft-side nested
            // entries (which carry their own draftId) aren't filtered out.
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
