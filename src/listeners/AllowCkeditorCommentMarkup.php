<?php

namespace markhuot\craftai\listeners;

use craft\htmlfield\events\ModifyPurifierConfigEvent;

/**
 * Teach the CKEditor field's HTML Purifier config about the
 * `<span data-craft-ai-comment-id="…">` marker the comment toolbar plugin
 * writes, so it survives the field's save-time sanitizer.
 */
class AllowCkeditorCommentMarkup
{
    public function __invoke(ModifyPurifierConfigEvent $event): void
    {
        $config = $event->config;

        /** @var \HTMLPurifier_HTMLDefinition|null $def */
        $def = $config->getDefinition('HTML', true);
        if ($def === null) {
            return;
        }

        // Allow our marker attribute on every `<span>` Craft
        // already permits. The value is a UUID — HTMLPurifier's
        // `Text` matches what we want (a token-like string) and
        // keeps anything weird from sneaking through. We also
        // need `class` on span so the editor's downcast can tag
        // the marker for styling without GeneralHtmlSupport
        // wiping the class off on round-trip.
        $def->addAttribute('span', 'data-craft-ai-comment-id', 'Text');
        $def->addAttribute('span', 'class', 'Text');
    }
}
