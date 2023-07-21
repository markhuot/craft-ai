<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\MapFromInput;
use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\ActiveRecord;
use markhuot\craftai\db\Table;

class PendingCall extends ActiveRecord
{
    #[MapFromInput('backend_id')]
    public ?Backend $backend = null;

    protected array $casts = [
        'backend' => ModelCast::class,
    ];

    public static function tableName()
    {
        return Table::PENDING_CALLS;
    }

    function toToken()
    {
        return '[pending:'.$this->id.']';
    }

    public function finish()
    {

    }
}
