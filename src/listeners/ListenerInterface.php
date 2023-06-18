<?php

namespace markhuot\craftai\listeners;

use yii\base\Event;

interface ListenerInterface
{
    public function handle(Event $event): void;
}
