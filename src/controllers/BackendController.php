<?php

namespace markhuot\craftai\controllers;

use craft\helpers\UrlHelper;
use markhuot\craftai\Ai;
use markhuot\craftai\backends\HuggingFace;
use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\backends\StableDiffusion;
use markhuot\craftai\models\Backend;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\web\Controller;

/**
 * @property Request $request
 */
class BackendController extends Controller
{
    public function actionIndex()
    {
        $config = \Craft::$app->config->getConfigFromFile('ai');

        return $this->renderTemplate('ai/_backends/index', [
            'backends' => Backend::find()->all(),
            'settings' => Ai::getInstance()->getSettings(),
            'isFakesSetInFileConfig' => isset($config['useFakes']),
        ]);
    }

    public function actionToggleFakes()
    {
        $settings = Ai::getInstance()->getSettings();
        $settings->useFakes = ! $settings->useFakes;
        \Craft::$app->getPlugins()->savePluginSettings(Ai::getInstance(), $settings->toArray());

        return $this->response(
            json: ['success' => true, 'value' => $settings->useFakes],
            html: fn () => $this->redirect(UrlHelper::cpUrl('ai/backends')),
        );
    }

    public function actionCreate(string $type)
    {
        return $this->cpEditScreen(match ($type) {
            'openai' => new OpenAi,
            'stable-diffusion' => new StableDiffusion,
            'hugging-face' => new HuggingFace,
            default => throw new \RuntimeException('Could not find backend for ['.$type.']'),
        });
    }

    public function actionEdit(int $id)
    {
        return $this->cpEditScreen(Backend::find()->where(['id' => $id])->one());
    }

    protected function cpEditScreen(?Backend $backend = null)
    {
        $screen = $this->asCpScreen()
            ->title(($backend?->isNewRecord ? 'Create' : 'Edit').' Backend')
            ->selectedSubnavItem('backends')
            ->addCrumb('Backends', UrlHelper::cpUrl('ai/backends'))
            ->action('ai/backend/store')
            ->redirectUrl('ai/backends')
            ->saveShortcutRedirectUrl('ai/backend/{id}')
            ->editUrl($backend?->id ? 'ai/backend/'.$backend?->id : null)
            ->contentTemplate('ai/_backends/create', [
                'backend' => $backend,
            ]);

        if (! $backend->isNewRecord) {
            $screen
                ->addAltAction('Save and continue editing', [
                    'action' => 'ai/backend/store',
                    'redirect' => 'ai/backend/'.$backend?->id,
                    'shortcut' => 's',
                ])
                ->addAltAction('Delete', [
                    'destructive' => true,
                    'action' => 'ai/backend/delete',
                    'params' => ['backend' => $backend?->id],
                    'redirect' => 'ai/backends',
                    'confirm' => 'Are you sure you want to delete this backend?',
                ]);
        }

        return $screen;
    }

    public function actionStore()
    {
        $backend = $this->request->getBodyParamObject(Backend::class);
        $backend->save();

        return $this->asModelSuccess($backend, 'Backend saved');
    }

    public function actionDelete()
    {
        $this->request
            ->getBodyParamObject(Backend::class)
            ->delete();

        $this->asSuccess('Backend deleted');

        return $this->redirectToPostedUrl();
    }
}
