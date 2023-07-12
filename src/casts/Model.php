<?php

namespace markhuot\craftai\casts;

use craft\base\ElementInterface;
use craft\models\Volume;
use markhuot\craftai\db\ActiveRecord;
use function markhuot\openai\helpers\throw_if;
use function markhuot\openai\helpers\web\app;

class Model implements CastInterface
{
    public function get(ActiveRecord $model, string $key, mixed $value): mixed
    {
        return $value;
    }

    public function set(ActiveRecord $model, string $key, mixed $value): mixed
    {
        $reflect = new \ReflectionClass($model);
        $className = null;
        $property = $reflect->getProperty($key);
        $propertyType = $property->getType();
        if ($propertyType && is_a($propertyType, \ReflectionNamedType::class)) {
            $className = $propertyType->getName();
        }
        $casts = $property->getAttributes(CastTo::class);
        if ($casts[0] ?? false) {
            $arguments = $casts[0]->getArguments();
            if ($arguments[0] ?? false) {
                $className = $arguments[0];
            }
        }
        throw_if(! $className, 'Can not determine the class name ['.$key.'] should be cast to');

        if (is_numeric($value)) {
            switch ($className) {
                case Volume::class: return app()->volumes->getVolumeById((int) $value);
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
                case Volume::class: return app()->volumes->getVolumeByHandle($value);
            }
        }

        throw new \RuntimeException('Could not cast ['.$value.'] to ['.(string) $property->getType().']');
    }
}
