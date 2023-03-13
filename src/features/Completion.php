<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\TextCompletionResponse;

interface Completion
{
    public function completeText(string $text): TextCompletionResponse;
}
