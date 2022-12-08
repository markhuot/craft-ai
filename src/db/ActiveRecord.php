<?php

namespace markhuot\craftai\db;

class ActiveRecord extends \craft\db\ActiveRecord
{
    use CastsAttributes;

    protected array $defaultValues = [];
    public static $keyField = 'id';
    public static $polymorphicKeyField = false;

    public function init()
    {
        parent::init();

        foreach ($this->defaultValues as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    public static function firstOrNew($condition)
    {
        $record = static::find()->where($condition)->asArray()->one();

        $model = static::make($record);
        $model->setIsNewRecord(empty($record));

        return $model;
    }

    static function make(?array $record=[])
    {
        $type = static::$polymorphicKeyField ? ($record[static::$polymorphicKeyField] ?? static::class) : static::class;

        $model = new $type;
        $model->setAttributes($record, false);
        return $model;
    }

    public static function instantiate($record)
    {
        $type = static::$polymorphicKeyField ? ($record[static::$polymorphicKeyField] ?? static::class) : static::class;

        return new $type;
    }
}
