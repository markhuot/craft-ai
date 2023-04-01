<?php

namespace markhuot\craftai\casts;

use craft\base\ElementInterface;
use craft\models\Volume;
use markhuot\craftai\db\ActiveRecord;

class Model
{
    public function get($model, $key, $value)
    {
        return $value;
    }

    public function set($model, $key, $value)
    {
        $reflect = new \ReflectionClass($model);
        $property = $reflect->getProperty($key);
        $className = $property->getType()?->getName();
        if (! $className) {
            return;
        }

        if (is_numeric($value)) {
            switch ($className) {
                case Volume::class: return \Craft::$app->volumes->getVolumeById($value);
            }

            if (isset(class_implements($className)[ElementInterface::class])) {
                return $className::find()->id($value)->one();
            }

            if (is_subclass_of($className, ActiveRecord::class)) {
                return $className::firstOrFail(['id' => $value]);
            }
        }

        if (is_string($value)) {
            switch ($className) {
                case Volume::class: return \Craft::$app->volumes->getVolumeByHandle($value);
            }
        }

        throw new \RuntimeException('Could not cast ['.$value.'] to ['.(string) $property->getType().']');
    }
}
