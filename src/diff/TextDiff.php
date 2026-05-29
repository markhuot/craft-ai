<?php

namespace markhuot\craftai\diff;

/**
 * Word-level longest-common-subsequence diff between two strings, returning a
 * flat list of segments tagged eq/del/ins. Used by {@see RevisionDiffService}
 * for text-ish field values (PlainText, CKEditor, title, slug). Pure and
 * dependency-free.
 *
 * Guards against pathological inputs: each side is truncated to MAX_CHARS, and
 * when the token grid would exceed MAX_CELLS the diff degrades to a single
 * delete-then-insert (still correct, just not granular) so a giant field can't
 * pin a queue worker on an O(n·m) table.
 */
final class TextDiff
{
    private const MAX_CHARS = 200_000;

    private const MAX_CELLS = 500_000;

    /**
     * @return list<array{op: 'eq'|'del'|'ins', text: string}>
     */
    public static function segments(string $old, string $new): array
    {
        $old = self::cap($old);
        $new = self::cap($new);

        if ($old === $new) {
            return $old === '' ? [] : [['op' => 'eq', 'text' => $old]];
        }

        $a = self::tokenize($old);
        $b = self::tokenize($new);

        $n = count($a);
        $m = count($b);

        if ($n === 0) {
            return [['op' => 'ins', 'text' => $new]];
        }
        if ($m === 0) {
            return [['op' => 'del', 'text' => $old]];
        }
        if ($n * $m > self::MAX_CELLS) {
            return [
                ['op' => 'del', 'text' => $old],
                ['op' => 'ins', 'text' => $new],
            ];
        }

        return self::coalesce(self::lcsMerge($a, $b));
    }

    private static function cap(string $s): string
    {
        return strlen($s) <= self::MAX_CHARS ? $s : substr($s, 0, self::MAX_CHARS);
    }

    /**
     * Split into words and the whitespace runs between them, keeping the
     * separators so reassembling the segments reproduces the original string
     * byte-for-byte.
     *
     * @return list<string>
     */
    private static function tokenize(string $s): array
    {
        if ($s === '') {
            return [];
        }
        $parts = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [$s] : $parts;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{op: 'eq'|'del'|'ins', text: string}>
     */
    private static function lcsMerge(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        /** @var array<int, array<int, int>> $dp */
        $dp = [];
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        /** @var list<array{op: 'eq'|'del'|'ins', text: string}> $segments */
        $segments = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $segments[] = ['op' => 'eq', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $segments[] = ['op' => 'del', 'text' => $a[$i]];
                $i++;
            } else {
                $segments[] = ['op' => 'ins', 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $segments[] = ['op' => 'del', 'text' => $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $segments[] = ['op' => 'ins', 'text' => $b[$j]];
            $j++;
        }

        return $segments;
    }

    /**
     * Merge runs of the same op into a single segment so the renderer emits one
     * `<ins>`/`<del>` per change rather than one per word.
     *
     * @param  list<array{op: 'eq'|'del'|'ins', text: string}>  $segments
     * @return list<array{op: 'eq'|'del'|'ins', text: string}>
     */
    private static function coalesce(array $segments): array
    {
        /** @var list<array{op: 'eq'|'del'|'ins', text: string}> $out */
        $out = [];
        foreach ($segments as $seg) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['op'] === $seg['op']) {
                $out[$last]['text'] .= $seg['text'];
            } else {
                $out[] = $seg;
            }
        }

        return $out;
    }
}
