<?php

namespace markhuot\craftai\listeners;

use markhuot\craftai\behaviors\BodyParamObjectBehavior;
use yii\base\Event;

class AddBodyParamObjectBehavior
{
    function handle(Event $event)
    {
        \Craft::$app->request->attachBehaviors(['bodyParamObject' => BodyParamObjectBehavior::class]);
    }
}
