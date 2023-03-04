<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\ChatMessageResponse;

interface Chat
{
    function chat(array $messages): ChatMessageResponse;
}
