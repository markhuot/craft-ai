<?php

namespace markhuot\craftai\diff;

/**
 * Renders a structured diff from {@see RevisionDiffService} into a single,
 * self-contained HTML document: a `<!doctype html>` page with one inline
 * `<style>` block, **no `<script>`, and no external references**. That shape
 * is what makes it safe to serve through {@see \markhuot\craftai\controllers\ArtifactsController}
 * under a strict CSP and render inside a sandboxed iframe.
 *
 * Every value interpolated from entry content is HTML-escaped — including
 * CKEditor field HTML, which is shown as escaped source rather than rendered,
 * so authored markup can never execute even before the sandbox/CSP kick in.
 */
final class DiffRenderer
{
    /**
     * @param  array<string, mixed>  $diff  Structured diff from {@see RevisionDiffService::diff()}.
     */
    public function render(array $diff, string $title): string
    {
        $a = $this->asArray($diff['a'] ?? []);
        $b = $this->asArray($diff['b'] ?? []);
        $summary = $this->asArray($diff['summary'] ?? []);
        $fields = $this->asList($diff['fields'] ?? []);

        $changed = array_values(array_filter(
            $fields,
            fn (mixed $row): bool => is_array($row) && ($row['status'] ?? 'unchanged') !== 'unchanged',
        ));
        $unchanged = array_values(array_filter(
            $fields,
            fn (mixed $row): bool => is_array($row) && ($row['status'] ?? 'unchanged') === 'unchanged',
        ));

        $body = $this->renderHeader($title, $a, $b, $summary);

        if ($changed === []) {
            $body .= '<p class="empty">No differences between these two versions.</p>';
        } else {
            foreach ($changed as $row) {
                $body .= $this->renderField($this->asArray($row));
            }
        }

        $body .= $this->renderUnchangedFooter($unchanged);

        return $this->document($title, $body);
    }

    private function document(string $title, string $body): string
    {
        $css = $this->css();
        $safeTitle = $this->esc($title);

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$safeTitle}</title>
            <style>{$css}</style>
            </head>
            <body>
            <main class="diff">
            {$body}
            </main>
            </body>
            </html>
            HTML;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, mixed>  $summary
     */
    private function renderHeader(string $title, array $a, array $b, array $summary): string
    {
        $labelA = $this->esc($this->asString($a['label'] ?? 'A'));
        $labelB = $this->esc($this->asString($b['label'] ?? 'B'));
        $metaA = $this->esc($this->versionMeta($a));
        $metaB = $this->esc($this->versionMeta($b));

        $changed = $this->asInt($summary['changed'] ?? 0);
        $added = $this->asInt($summary['added'] ?? 0);
        $removed = $this->asInt($summary['removed'] ?? 0);
        $unchanged = $this->asInt($summary['unchanged'] ?? 0);

        return <<<HTML
            <header class="head">
            <h1>{$this->esc($title)}</h1>
            <div class="versions">
            <span class="ver ver--a"><strong>{$labelA}</strong><span class="meta">{$metaA}</span></span>
            <span class="arrow" aria-hidden="true">&rarr;</span>
            <span class="ver ver--b"><strong>{$labelB}</strong><span class="meta">{$metaB}</span></span>
            </div>
            <ul class="summary">
            <li class="s-changed">{$changed} changed</li>
            <li class="s-added">{$added} added</li>
            <li class="s-removed">{$removed} removed</li>
            <li class="s-unchanged">{$unchanged} unchanged</li>
            </ul>
            </header>
            HTML;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function renderField(array $row): string
    {
        $name = $this->esc($this->asString($row['name'] ?? ''));
        $type = $this->esc($this->asString($row['type'] ?? ''));
        $kind = $this->asString($row['kind'] ?? '');
        $status = $this->asString($row['status'] ?? 'changed');
        $detail = $this->asArray($row['detail'] ?? []);

        if (($status === 'added' || $status === 'removed') && array_key_exists('value', $detail)) {
            // A whole field was added to / removed from the layout — show its
            // single-sided value regardless of kind.
            $inner = $this->renderScalar($detail, $status);
        } else {
            $inner = match ($kind) {
                'text' => $this->renderText($detail, $status),
                'relation' => $this->renderRelation($detail),
                'matrix' => $this->renderMatrix($detail),
                default => $this->renderScalar($detail, $status),
            };
        }

        return <<<HTML
            <section class="field field--{$this->esc($status)}">
            <div class="field-head">
            <span class="badge badge--{$this->esc($status)}">{$this->esc($status)}</span>
            <span class="field-name">{$name}</span>
            <span class="field-type">{$type}</span>
            </div>
            <div class="field-body">{$inner}</div>
            </section>
            HTML;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function renderText(array $detail, string $status): string
    {
        $segments = $this->asList($detail['textDiff'] ?? []);
        if ($segments === []) {
            // added/removed text fields carry a plain `value`
            $value = $this->asString($detail['value'] ?? '');

            return '<div class="text">'.nl2br($this->esc($value)).'</div>';
        }

        $html = '';
        foreach ($segments as $segment) {
            $seg = $this->asArray($segment);
            $op = $this->asString($seg['op'] ?? 'eq');
            $text = nl2br($this->esc($this->asString($seg['text'] ?? '')));
            $html .= match ($op) {
                'del' => "<del>{$text}</del>",
                'ins' => "<ins>{$text}</ins>",
                default => "<span>{$text}</span>",
            };
        }

        return '<div class="text">'.$html.'</div>';
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function renderScalar(array $detail, string $status): string
    {
        if (array_key_exists('value', $detail)) {
            $cls = $status === 'removed' ? 'del' : 'ins';

            return '<div class="scalar"><'.$cls.'>'.nl2br($this->esc($this->asString($detail['value']))).'</'.$cls.'></div>';
        }

        $from = nl2br($this->esc($this->asString($detail['from'] ?? '')));
        $to = nl2br($this->esc($this->asString($detail['to'] ?? '')));

        return <<<HTML
            <div class="scalar">
            <div class="from"><del>{$from}</del></div>
            <div class="to"><ins>{$to}</ins></div>
            </div>
            HTML;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function renderRelation(array $detail): string
    {
        $added = $this->asList($detail['added'] ?? []);
        $removed = $this->asList($detail['removed'] ?? []);
        $reordered = (bool) ($detail['reordered'] ?? false);

        $html = '<div class="relation">';
        foreach ($removed as $item) {
            $html .= '<div class="rel rel--removed"><del>'.$this->esc($this->relLabel($item)).'</del></div>';
        }
        foreach ($added as $item) {
            $html .= '<div class="rel rel--added"><ins>'.$this->esc($this->relLabel($item)).'</ins></div>';
        }
        if ($added === [] && $removed === [] && $reordered) {
            $html .= '<div class="note">Related items reordered.</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function renderMatrix(array $detail): string
    {
        $blocks = $this->asList($detail['blocks'] ?? []);
        $reordered = (bool) ($detail['reordered'] ?? false);

        $html = '<div class="matrix">';
        if ($reordered) {
            $html .= '<div class="note">Blocks reordered.</div>';
        }
        foreach ($blocks as $block) {
            $b = $this->asArray($block);
            $status = $this->asString($b['status'] ?? 'changed');
            $type = $this->esc($this->asString($b['type'] ?? ''));
            $blockId = $this->esc($this->asString($b['blockId'] ?? ''));

            $html .= '<div class="block block--'.$this->esc($status).'">';
            $html .= '<div class="block-head"><span class="badge badge--'.$this->esc($status).'">'.$this->esc($status).'</span> <span class="block-type">'.$type.'</span> <span class="block-id">#'.$blockId.'</span></div>';

            foreach ($this->asList($b['fields'] ?? []) as $subRow) {
                $html .= $this->renderField($this->asArray($subRow));
            }

            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param  list<mixed>  $unchanged
     */
    private function renderUnchangedFooter(array $unchanged): string
    {
        if ($unchanged === []) {
            return '';
        }

        $names = [];
        foreach ($unchanged as $row) {
            $name = $this->asString($this->asArray($row)['name'] ?? '');
            if ($name !== '') {
                $names[] = $this->esc($name);
            }
        }
        if ($names === []) {
            return '';
        }

        return '<footer class="unchanged">Unchanged: '.implode(', ', $names).'</footer>';
    }

    /**
     * @param  array<string, mixed>  $version
     */
    private function versionMeta(array $version): string
    {
        $parts = [];
        $savedBy = $this->asString($version['savedBy'] ?? '');
        if ($savedBy !== '') {
            $parts[] = 'by '.$savedBy;
        }
        $date = $this->asString($version['dateUpdated'] ?? '');
        if ($date !== '') {
            $parts[] = substr($date, 0, 16);
        }

        return implode(' · ', $parts);
    }

    private function relLabel(mixed $item): string
    {
        $arr = $this->asArray($item);
        $title = $this->asString($arr['title'] ?? '');
        $id = $this->asString($arr['id'] ?? '');

        return $title !== '' ? $title : ('#'.$id);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function asArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        /** @var array<string, mixed> $out */
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function css(): string
    {
        return <<<'CSS'
            *{box-sizing:border-box}
            body{margin:0;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2328;background:#fff}
            .diff{max-width:920px;margin:0 auto;padding:20px}
            .head{border-bottom:1px solid #d0d7de;padding-bottom:16px;margin-bottom:16px}
            .head h1{font-size:18px;margin:0 0 12px}
            .versions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
            .ver{display:flex;flex-direction:column;padding:6px 10px;border:1px solid #d0d7de;border-radius:6px;background:#f6f8fa}
            .ver strong{font-size:13px}
            .ver .meta{font-size:11px;color:#656d76}
            .arrow{color:#656d76;font-size:18px}
            .summary{display:flex;gap:8px;list-style:none;padding:0;margin:0;flex-wrap:wrap}
            .summary li{font-size:12px;padding:2px 8px;border-radius:999px;background:#eaeef2}
            .s-changed{background:#fff3cd;color:#7a5b00}
            .s-added{background:#dafbe1;color:#0a5223}
            .s-removed{background:#ffebe9;color:#82071e}
            .field{border:1px solid #d0d7de;border-radius:6px;margin-bottom:12px;overflow:hidden}
            .field-head{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f6f8fa;border-bottom:1px solid #d0d7de}
            .field-name{font-weight:600}
            .field-type{font-size:11px;color:#656d76;margin-left:auto}
            .badge{font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:2px 6px;border-radius:999px;font-weight:600}
            .badge--changed{background:#fff3cd;color:#7a5b00}
            .badge--added{background:#dafbe1;color:#0a5223}
            .badge--removed{background:#ffebe9;color:#82071e}
            .badge--reordered{background:#ddf4ff;color:#0550ae}
            .field-body{padding:12px}
            .text{white-space:normal;word-wrap:break-word}
            del{background:#ffebe9;color:#82071e;text-decoration:line-through;text-decoration-color:#cf222e}
            ins{background:#dafbe1;color:#0a5223;text-decoration:none}
            .scalar .from,.scalar .to{margin:2px 0}
            .relation .rel{margin:2px 0}
            .note{font-size:12px;color:#656d76;font-style:italic}
            .block{border:1px dashed #d0d7de;border-radius:6px;margin:8px 0;padding:8px}
            .block-head{display:flex;align-items:center;gap:8px;margin-bottom:6px}
            .block-type{font-weight:600}
            .block-id{font-size:11px;color:#656d76}
            .empty{color:#656d76;text-align:center;padding:24px}
            .unchanged{margin-top:16px;padding-top:12px;border-top:1px solid #d0d7de;font-size:12px;color:#656d76}
            CSS;
    }
}
