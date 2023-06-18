<?php

namespace markhuot\craftai\listeners;

use markhuot\craftai\behaviors\BodyParamObjectBehavior;

class AddBodyParamObjectBehavior
{
    public function handle(): void
    {
        \Craft::$app->request->attachBehaviors(['bodyParamObject' => BodyParamObjectBehavior::class]);
    }
}
