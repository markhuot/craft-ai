<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use yii\web\Response;

class AssetsController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    /**
     * Resolve a list of asset IDs to the metadata the chat UI needs to render
     * an attachment chip: id, label, kind (so we can pick an icon), and a
     * small CP thumbnail URL. Hits Craft's asset thumb pipeline so the same
     * on-demand transform/icon logic the rest of the CP uses applies here.
     */
    public function actionInfo(): Response
    {
        $this->requireLogin();
        $this->requireAcceptsJson();

        $param = $this->request->getQueryParam('ids');
        if ($param === null || $param === '') {
            $param = $this->request->getBodyParam('ids');
        }

        $ids = $this->normalizeIds($param);

        if ($ids === []) {
            return $this->asJson(['assets' => []]);
        }

        $assets = Asset::find()->id($ids)->status(null)->all();
        $service = Craft::$app->getAssets();

        $byId = [];
        foreach ($assets as $asset) {
            $byId[$asset->id] = [
                'id' => $asset->id,
                'label' => $asset->title ?: $asset->filename,
                'filename' => $asset->filename,
                'kind' => $asset->kind,
                'mimeType' => $asset->getMimeType(),
                'thumbUrl' => $service->getThumbUrl($asset, 60, 60, true),
            ];
        }

        // Preserve caller order; drop missing assets so the client can detect a stale ID.
        $payload = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $payload[] = $byId[$id];
            }
        }

        return $this->asJson(['assets' => $payload]);
    }

    /**
     * @return list<int>
     */
    private function normalizeIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return [];
                }
                $value = is_array($decoded) ? $decoded : [];
            } else {
                $value = explode(',', $trimmed);
            }
        }

        if (is_int($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[] = $entry;
                continue;
            }
            if (is_string($entry)) {
                $entry = trim($entry);
                if ($entry !== '' && ctype_digit($entry)) {
                    $intVal = (int) $entry;
                    if ($intVal > 0) {
                        $ids[] = $intVal;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
