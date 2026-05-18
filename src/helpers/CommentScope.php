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
 * to the canonical id otherwise. Mixed-draft scenarios (a draft entry
 * with non-draft nested blocks, etc.) are handled because the controller
 * filters comments by the `(elementId, isDraft)` pair recorded at
 * leave-time, not by a single isDraft flag.
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
     * Convenience flatten: just the IDs, ignoring isDraft. Matches the
     * old single-element query shape so callers that don't care about
     * draft/canonical disambiguation can stay simple.
     *
     * @return list<int>
     */
    public static function idsFor(int $elementId, bool $isDraft): array
    {
        return array_values(array_map(
            static fn (array $pair): int => $pair[0],
            self::pairsFor($elementId, $isDraft),
        ));
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
