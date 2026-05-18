<?php

namespace markhuot\craftai\helpers;

use craft\helpers\HtmlPurifier;
use yii\helpers\Markdown;

/**
 * Render a comment body — a short markdown string produced by the LLM — into
 * sanitized HTML the comment-overlay JS can drop into the popover via
 * `innerHTML`. We render markdown so reviews look the same in the inline
 * comment popover as they do in the main chat (which uses react-markdown +
 * remark-gfm).
 *
 * The renderer runs Yii's GitHub-flavored markdown processor first, then
 * pipes the result through HTMLPurifier so any inline HTML the LLM (or a
 * prompt-injected user) may have smuggled into the comment body can't reach
 * the editor's browser as executable markup. The purifier is configured with
 * a deliberately small allow-list — text and links only — because review
 * comments shouldn't be embedding images, iframes, or scripts.
 */
final class CommentMarkdown
{
    /**
     * Convert a comment body to sanitized HTML. Returns an empty string when
     * the input is empty so callers can blindly assign it to innerHTML
     * without having to special-case missing comments.
     */
    public static function render(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $html = Markdown::process($body, 'gfm');

        // The purifier allows the tags GFM actually produces (paragraphs,
        // emphasis, lists, code, links, tables, blockquotes) and strips
        // everything else. `HTML.Trusted = false` (default) plus the
        // explicit allow-list means inline `<script>` / `<iframe>` /
        // event-handler attributes get dropped.
        return HtmlPurifier::process($html, [
            'HTML.Allowed' => 'p,br,strong,em,b,i,u,code,pre,a[href|title|rel],'
                .'ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,'
                .'table,thead,tbody,tr,th,td,hr,del,s,sub,sup',
            'HTML.SafeIframe' => false,
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => true,
            'Attr.AllowedFrameTargets' => ['_blank'],
            'HTML.Nofollow' => true,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
        ]);
    }
}
