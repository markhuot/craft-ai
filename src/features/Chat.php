<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\ChatMessageResponse;

interface Chat
{
    /**
     * @param  array<array-key, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages): ChatMessageResponse;
}
