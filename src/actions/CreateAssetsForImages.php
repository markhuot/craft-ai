<?php

namespace markhuot\craftai\actions;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use function markhuot\openai\helpers\throw_if;

class CreateAssetsForImages
{
    /**
     * @param  string[]  $imagePaths
     * @return Asset[]
     */
    public function handle(Volume $volume, array $imagePaths): array
    {
        $assets = [];

        foreach ($imagePaths as $path) {
            $asset = new Asset;
            $asset->newFilename = 'image.'.time().'.png';
            $asset->newFolderId = Craft::$app->assets->getRootFolderByVolumeId($volume->id)->id;
            $asset->tempFilePath = $path;
            $asset->uploaderId = Craft::$app->getUser()->getId();
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            throw_if(! Craft::$app->elements->saveElement($asset), 'Could not save generated asset');

            $assets[] = $asset;
        }

        return $assets;
    }
}
