<?php

namespace markhuot\craftai\controllers;

use craft\web\Controller;
use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\backends\StableDiffusion;
use markhuot\craftai\models\Backend;
use markhuot\craftai\stubs\Request;

/**
 * @property Request $request
 */
class BackendController extends Controller
{
    function actionIndex()
    {
        return $this->renderTemplate('ai/backends/index', [
            'backends' => Backend::find()->all(),
        ]);
    }

    function actionCreate(string $type)
    {
        switch ($type) {
            case 'openai': $backend = new OpenAi; break;
            case 'stable-diffusion': $backend = new StableDiffusion; break;
            default: throw new \RuntimeException('Could not find backend for [' . $type . ']');
        }

        return $this->renderTemplate('ai/backends/create', [
            'backend' => $backend,
        ]);
    }

    function actionEdit(int $id)
    {
        return $this->renderTemplate('ai/backends/create', [
            'backend' => Backend::find()->where(['id' => $id])->one(),
        ]);
    }

    function actionStore()
    {
        $this->requirePostRequest();

        $this->request
            ->getBodyParamObject(Backend::class)
            ->save();

        return $this->redirectToPostedUrl();
    }
}
