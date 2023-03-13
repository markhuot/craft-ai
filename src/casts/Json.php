<?php

namespace markhuot\craftai\casts;

class Json
{
    public function get($model, $key, $value)
    {
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
    public function set($model, $key, $value)
    {
        return $value; // json_encode($value, JSON_THROW_ON_ERROR);
    }
}
