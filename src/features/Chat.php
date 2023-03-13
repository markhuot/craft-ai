<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\ChatMessageResponse;

interface Chat
{
    public function chat(array $messages): ChatMessageResponse;
}
