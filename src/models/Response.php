<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\Boolean;
use markhuot\craftai\casts\MapFromInput;
use markhuot\craftai\casts\Model;
use markhuot\craftai\db\ActiveRecord;
use markhuot\craftai\db\Table;

/**
 * @property bool $pending
 */
class Response extends ActiveRecord
{
    #[MapFromInput('backend_id')]
    public Backend $backend;

    /** @var array<string, mixed> */
    protected array $defaultValues = [
        'type' => self::class,
    ];

    protected array $casts = [
        'pending' => Boolean::class,
        'backend' => Model::class,
    ];

    public static ?string $polymorphicKeyField = 'type';

    public static function tableName()
    {
        return Table::RESPONSES;
    }

    public function finish(): self
    {
        $this->backend->finish($this);

        return $this;
    }
}
