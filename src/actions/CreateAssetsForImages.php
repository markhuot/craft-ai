<?php

namespace markhuot\craftai\actions;

use craft\elements\Asset;
use craft\models\Volume;
use function markhuot\openai\helpers\throw_if;
use function markhuot\openai\helpers\web\app;

class CreateAssetsForImages
{
    /**
     * @param  string[]  $imagePaths
     * @return Asset[]
     */
    public function handle(Volume $volume, array $imagePaths): array
    {
        $assets = [];
        throw_if(! $volume->id, 'Volume must have an ID');

        foreach ($imagePaths as $path) {
            $asset = new Asset;
            $asset->newFilename = 'image.'.time().'.png';
            $asset->newFolderId = app()->getAssets()->getRootFolderByVolumeId($volume->id)->id; // @phpstan-ignore-line the outer ->id is not typed on a volume folder yet
            $asset->tempFilePath = $path;
            $asset->uploaderId = (int) app()->getUser()->getId();
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);
            throw_if(! app()->getElements()->saveElement($asset), 'Could not save generated asset');

            $assets[] = $asset;
        }

        return $assets;
    }
}
