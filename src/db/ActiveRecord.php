<?php

namespace markhuot\craftai\db;

use yii\db\Expression;
use function markhuot\openai\helpers\throw_if;

/**
 * @property string $id
 */
class ActiveRecord extends \craft\db\ActiveRecord
{
    use CastsAttributes;

    /**
     * @var array<mixed>
     */
    protected array $defaultValues = [];

    public static string $keyField = 'id';

    public static ?string $polymorphicKeyField = null;

    public function init(): void
    {
        parent::init();

        foreach ($this->defaultValues as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * @param  array<mixed>|Expression  $condition
     */
    public static function firstOrNew(array|Expression $condition): self
    {
        /**
         * @var ?array<mixed> $record Narrow and remove `ActiveRecord` from the
         *                            possible ->one() return types
         */
        $record = static::find()->where($condition)->asArray()->one();

        $model = static::make($record ?? []);
        $model->setIsNewRecord(empty($record));

        return $model;
    }

    /**
     * @param  array<mixed>|Expression  $condition
     */
    public static function firstOrFail(array|Expression $condition): self
    {
        /**
         * @var ?array<mixed> $record Narrow and remove `ActiveRecord` from the
         *                            possible ->one() return types
         */
        $record = static::find()->where($condition)->asArray()->one();

        if (empty($record)) {
            throw new \RuntimeException('Record not found');
        }

        $model = static::make($record);
        $model->setIsNewRecord(false);

        return $model;
    }

    /**
     * @param  array<mixed>  $record
     */
    public static function make(array $record = []): self
    {
        /** @var class-string<ActiveRecord> $type */
        $type = static::$polymorphicKeyField ? ($record[static::$polymorphicKeyField] ?? static::class) : static::class;

        $model = new $type;
        $model->setAttributes($record, false);

        return $model;
    }

    /**
     * @param  array<mixed>  $row
     * @return self
     */
    public static function instantiate($row)
    {
        /** @var class-string<ActiveRecord> $type */
        $type = static::$polymorphicKeyField ? ($row[static::$polymorphicKeyField] ?? static::class) : static::class;

        return \Craft::$container->get($type); // @phpstan-ignore-line
    }

    public function fresh(): self
    {
        /** @var ?self $model */
        $model = static::find()->where([static::$keyField => $this->{static::$keyField}])->one();
        throw_if(! $model, 'No fresh model found');

        return $model;
    }
}
