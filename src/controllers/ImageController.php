<?php

namespace markhuot\craftai\controllers;

use craft\db\Table;
use craft\elements\Asset;
use craft\web\Controller;
use markhuot\craftai\actions\CreateAssetsForImages;
use markhuot\craftai\features\Caption;
use markhuot\craftai\features\EditImage;
use markhuot\craftai\features\GenerateImage;
use function markhuot\craftai\helpers\app;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\EditImagePostRequest;
use markhuot\craftai\models\GenerateImagePostRequest;
use markhuot\craftai\stubs\Request;
use function markhuot\openai\helpers\throw_if;
use yii\web\Response;

/**
 * @property Request $request
 */
class ImageController extends Controller
{
    public function actionGenerate(): Response
    {
        $assetIds = $this->request->getQueryParam('assets', []);
        throw_if(! is_array($assetIds), 'Unexpected asset param');

        $assetIds = array_filter($assetIds);
        $assets = ! empty($assetIds) ? Asset::find()->id($assetIds)->all() : [];

        return $this->renderTemplate('ai/_images/generate', [
            'backends' => Backend::allFor(GenerateImage::class),
            'assets' => $assets,
            'prompt' => $this->request->getQueryParam('prompt'),
        ]);
    }

    public function actionStoreGeneration(): Response
    {
        $data = $this->request->getBodyParamObject(GenerateImagePostRequest::class);

        $backend = $data->backend ?? Backend::for(GenerateImage::class);

        $response = $backend->generateImage($data->prompt, $data->count);
        $assets = app(CreateAssetsForImages::class)->handle($data->volume, $response->paths);

        $this->setSuccessFlash('Generated '.count($assets).' assets');
        $params = array_map(fn ($a) => 'assets[]='.$a->id, $assets);

        return $this->redirect('ai/images/generate?prompt='.urlencode($data->prompt).'&'.implode('&', $params));
    }

    public function actionCaption(): Response
    {
        /** @var ?Asset $asset */
        $asset = Asset::find()->id($this->request->getBodyParam('elementId'))->one();
        throw_if(! $asset, 'Invalid asset id');

        $caption = Backend::for(Caption::class)->generateCaption($asset);

        \Craft::$app->db->createCommand()
            ->update(Table::ASSETS, ['caption' => $caption->caption], ['id' => $asset->id])
            ->execute();

        return $this->redirect($asset->cpEditUrl);
    }

    public function actionEdit(): Response
    {
        return $this->renderTemplate('ai/_images/edit', [
            'asset' => Asset::find()->id($this->request->getQueryParam('assetId'))->one(),
            'prompt' => null,
            'backends' => Backend::allFor(EditImage::class),
        ]);
    }

    public function actionStoreEdit(): Response
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(EditImagePostRequest::class);

        $backend = $data->backend ?? Backend::for(EditImage::class);
        $response = $backend->editImage($data->prompt, $data->asset, $data->mask, $data->count);
        $assets = app(CreateAssetsForImages::class)->handle($data->asset->volume, $response->paths);

        $this->setSuccessFlash('Generated '.count($assets).' assets');

        return $this->redirect('ai/images/edit?prompt='.urlencode($data->prompt).'&assetId='.$assets[0]->id);
    }
}
