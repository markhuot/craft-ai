<?php

namespace markhuot\craftai\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\backends\HuggingFace;
use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\backends\StableDiffusion;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\BackendDeleteRequest;
use markhuot\craftai\stubs\Request;

/**
 * @property Request $request
 */
class BackendController extends Controller
{
    function actionIndex()
    {
        return $this->renderTemplate('ai/_backends/index', [
            'backends' => Backend::find()->all(),
        ]);
    }

    function actionCreate(string $type)
    {
        switch ($type) {
            case 'openai': $backend = new OpenAi; break;
            case 'stable-diffusion': $backend = new StableDiffusion; break;
            case 'hugging-face': $backend = new HuggingFace; break;
            default: throw new \RuntimeException('Could not find backend for [' . $type . ']');
        }

        return $this->cpEditScreen($backend);
    }

    function actionEdit(int $id)
    {
        return $this->cpEditScreen(Backend::find()->where(['id' => $id])->one());
    }

    protected function cpEditScreen(?Backend $backend=null)
    {
        $screen = $this->asCpScreen()
            ->title(($backend?->isNewRecord ? 'Create' : 'Edit') . ' Backend')
            ->selectedSubnavItem('backends')
            ->addCrumb('Backends', UrlHelper::cpUrl('ai/backends'))
            ->action('ai/backend/store')
            ->redirectUrl(UrlHelper::prependCpTrigger('ai/backends'))
            ->contentTemplate('ai/_backends/create', [
                'backend' => $backend,
            ]);

        if (!$backend->isNewRecord) {
            $screen->addAltAction('Delete', [
                'destructive' => true,
                'action' => 'ai/backend/delete',
                'params' => ['backend' => $backend?->id],
                'redirect' => 'ai/backends',
                'confirm' => 'Are you sure you want to delete this backend?',
            ]);
        }

        return $screen;
    }

    function actionStore()
    {
        $this->requirePostRequest();

        $this->request
            ->getBodyParamObject(Backend::class)
            ->save();

        return $this->redirectToPostedUrl();
    }

    function actionDelete()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(BackendDeleteRequest::class);

        $data->backend->delete();

        return $this->redirectToPostedUrl();
    }
}
