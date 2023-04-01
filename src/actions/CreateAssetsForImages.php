<?php

namespace markhuot\craftai\actions;

use craft\elements\Asset;
use craft\models\Volume;

class CreateAssetsForImages
{
    /**
     * @param  string[]  $imagePaths
     */
    public function handle(Volume $volume, array $imagePaths)
    {
        $assets = [];

        foreach ($imagePaths as $path) {
            $asset = new Asset;
            $asset->newFilename = 'image.'.time().'.png';
            $asset->newFolderId = \Craft::$app->assets->getRootFolderByVolumeId($volume->id)->id;
            $asset->tempFilePath = $path;
            $asset->uploaderId = \Craft::$app->getUser()->getId();
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            if (! \Craft::$app->elements->saveElement($asset)) {
                // @todo, deal with this and show errors
                continue;
            }

            $assets[] = $asset;
        }

        return $assets;
    }
}
