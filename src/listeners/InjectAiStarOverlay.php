<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\events\TemplateEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;

/**
 * Append the per-field "AI fill" star overlay to every CP page response.
 *
 * Bundle behaviour mirrors the comments overlay — short-circuits when the
 * page lacks an `elementId` / `draftId` hidden input — so registering
 * globally is cheaper than trying to detect "is this an entry edit URL"
 * server-side and getting it wrong on third-party plugin routes.
 */
class InjectAiStarOverlay
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
        $sourcePath = dirname(__DIR__).'/web/assets/aistar/dist';

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
            'fillFieldUrl' => UrlHelper::actionUrl('craft-ai/ai-star/fill-field'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => $request->getCsrfToken(),
        ];

        $bootstrapJson = Json::htmlEncode($bootstrap);
        $jsUrl = $baseUrl.'/aistar.js';
        $cssUrl = $baseUrl.'/aistar.css';

        $snippet = <<<HTML
<link rel="stylesheet" href="{$cssUrl}">
<script type="application/json" data-craftai-aistar-bootstrap>{$bootstrapJson}</script>
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
