<?php

namespace markhuot\craftai\casts;

use markhuot\craftai\db\ActiveRecord;
use function markhuot\openai\helpers\throw_if;

class Json implements CastInterface
{
    public function get(ActiveRecord|\yii\base\Model $model, string $key, mixed $value): mixed
    {
        throw_if(! is_string($value), 'Expected a string to be cast to JSON');

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    // This is intentionally not implemented. Yii/Craft has this backwards and there's not
    // much we can do.
    //
    // When a model is at rest the attributes are stored in their DB friendly mode. That means
    // that a JSON casted field is stored as a JSON string, not as an array. There are a few places
    // that force this functionality such as Craft's helpers\Db::prepareValueForDb() which forces
    // array values back to a string.
    //
    // Ideally I'd like to flip/flop this and store the data in it's casted/expanded form so that
    // changes to a nested value can be serialized back, but the infra just isn't there in Yii so
    // we have to work within the confines of the framework.
    public function set(ActiveRecord|\yii\base\Model $model, string $key, mixed $value): mixed
    {
        return $value; // json_encode($value, JSON_THROW_ON_ERROR);
    }
}
