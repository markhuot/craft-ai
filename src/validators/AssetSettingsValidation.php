<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\base\FieldInterface;
use yii\validators\Validator;

/**
 * Validates that an Assets field's settings include a resolvable upload-location
 * volume key. Reads the sibling `id` parameter (when updating) so existing
 * settings are merged with user-provided overrides before checking — matching
 * how UpsertField hands the merged settings to `createField`.
 *
 * Runs in the bound phase so `id` (resolved to FieldInterface by the Field
 * binder) can be inspected directly.
 */
class AssetSettingsValidation extends Validator implements ValidatesBoundParameters
{
    public $skipOnEmpty = false;

    public function validateAttribute($model, $attribute): void
    {
        $userSettings = $model->{$attribute};
        $userSettings = is_array($userSettings) ? $userSettings : [];

        $existing = ($model->id ?? null) instanceof FieldInterface
            ? $model->id->getSettings()
            : [];

        $settings = array_merge($existing, $userSettings);

        $restrictLocation = (bool) ($settings['restrictLocation'] ?? false);

        if ($restrictLocation) {
            $key = $settings['restrictedLocationSource'] ?? null;
            $name = 'restrictedLocationSource';
            $label = 'Asset Location';
        } else {
            $key = $settings['defaultUploadLocationSource'] ?? null;
            $name = 'defaultUploadLocationSource';
            $label = 'Default Upload Location';
        }

        if (! is_string($key) || $key === '') {
            $this->addError(
                $model,
                $attribute,
                "settings.{$name} is required for Assets fields (the “{$label}” setting in the control panel). "
                .'Provide a volume source key in the form "volume:<uid>". Call get_volumes to discover available volume UIDs.'
            );

            return;
        }

        $parts = explode(':', $key, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            $this->addError(
                $model,
                $attribute,
                "settings.{$name} must be a volume source key in the form \"volume:<uid>\"; got \"{$key}\"."
            );

            return;
        }

        if (Craft::$app->volumes->getVolumeByUid($parts[1]) === null) {
            $this->addError(
                $model,
                $attribute,
                "settings.{$name} references a volume UID that does not exist (\"{$parts[1]}\"). Call get_volumes to discover available volume UIDs."
            );
        }
    }
}
