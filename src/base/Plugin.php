<?php

namespace markhuot\craftai\base;

use Craft;
use craft\base\Model;
use markhuot\craftai\models\Settings;

class Plugin extends \craft\base\Plugin
{
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init()
    {
        parent::init();

        Craft::setAlias('@ai', $this->getBasePath());
        $this->controllerNamespace = 'markhuot\\craftai\\controllers';
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings;
    }

    protected function settingsHtml(): ?string
    {
        return \Craft::$app->view->renderTemplate('ai/settings');
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $routes = include __DIR__.'/../config/routes.php';

        foreach ($routes as $id => $route) {
            if (empty($route['label'])) {
                continue;
            }

            $nav['subnav'][$id] = [
                'label' => $route['label'],
                'url' => $route['route'],
            ];
        }

        return $nav;
    }
}
