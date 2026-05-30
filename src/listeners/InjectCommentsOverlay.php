<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\events\TemplateEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;

/**
 * Append the AI review-comments overlay to every CP page response.
 *
 * The bundle's TS short-circuits if the page lacks an `elementId` /
 * `draftId` hidden input, so registering globally is cheaper than
 * detecting "is this an entry edit URL" server-side and getting it
 * wrong on third-party plugin routes. The overlay reads its own
 * bootstrap (CSRF + endpoint URLs) from a JSON script tag we inject
 * alongside the bundle reference.
 */
class InjectCommentsOverlay
{
    public function __invoke(TemplateEvent $event): void
    {
        if ($event->templateMode !== View::TEMPLATE_MODE_CP) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (! $request instanceof \craft\web\Request) {
            return;
        }
        if (! $request->getIsCpRequest() || $request->getIsAjax()) {
            return;
        }

        if (Craft::$app->getUser()->getIsGuest()) {
            return;
        }

        if ($event->output === '') {
            return;
        }

        $assetManager = Craft::$app->getAssetManager();
        $sourcePath = dirname(__DIR__).'/web/assets/comments/dist';

        try {
            $published = $assetManager->publish($sourcePath);
        } catch (\Throwable) {
            return;
        }

        $baseUrl = $published[1] ?? null;
        if (! is_string($baseUrl) || $baseUrl === '') {
            return;
        }

        $bootstrap = [
            'listUrl' => UrlHelper::cpUrl('ai/comments'),
            'resolveUrl' => UrlHelper::actionUrl('craft-ai/comments/resolve'),
            'openThreadUrl' => UrlHelper::actionUrl('craft-ai/comments/open-thread'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => $request->getCsrfToken(),
        ];

        $bootstrapJson = Json::htmlEncode($bootstrap);
        $jsUrl = $baseUrl.'/comments.js';
        $cssUrl = $baseUrl.'/comments.css';

        $snippet = <<<HTML
<link rel="stylesheet" href="{$cssUrl}">
<script type="application/json" data-craftai-comments-bootstrap>{$bootstrapJson}</script>
<script type="module" src="{$jsUrl}"></script>
HTML;

        if (str_contains($event->output, '</body>')) {
            $event->output = (string) preg_replace(
                '/<\/body>/i',
                $snippet."\n</body>",
                $event->output,
                1,
            );

            return;
        }

        $event->output .= $snippet;
    }
}
