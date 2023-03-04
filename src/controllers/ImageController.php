<?php

namespace markhuot\craftai\controllers;

use craft\db\Table;
use craft\elements\Asset;
use craft\web\Controller;
use markhuot\craftai\actions\CreateAssetsForImages;
use markhuot\craftai\features\Caption;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\GenerateImagePostRequest;
use markhuot\craftai\stubs\Request;

/**
 * @property Request $request
 */
class ImageController extends Controller
{
    function actionIndex()
    {
        $assetIds = array_filter($this->request->getQueryParam('assets', []));
        $assets = !empty($assetIds) ? Asset::find()->id($assetIds)->all() : [];

        return $this->renderTemplate('ai/_images/index', [
            'backends' => Backend::find()->all(),
            'assets' => $assets,
        ]);
    }

    function actionCreate()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(GenerateImagePostRequest::class);

        $response = ($data->backend ?? Backend::for(GenerateImage::class))->generateImage($data->prompt);
        $assets = \Craft::$container->get(CreateAssetsForImages::class)->handle($data->volume, $response->paths);

        $this->setSuccessFlash('Generated ' . count($assets) . ' assets');
        $params = array_map(fn ($a) => 'assets[]='.$a->id, $assets);
        return $this->redirect('ai/images?'.implode('&', $params));
    }

    function actionCaption()
    {
        $asset = Asset::find()->id($this->request->getBodyParam('elementId'))->one();
        $caption = Backend::for(Caption::class)->generateCaption($asset);

        \Craft::$app->db->createCommand()
            ->update(Table::ASSETS, ['caption' => $caption->caption], ['id' => $asset->id])
            ->execute();

        return $this->redirect($asset->cpEditUrl);
    }
}
