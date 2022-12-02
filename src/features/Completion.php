<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\TextCompletionResponse;

interface Completion
{
    function completeText(string $text): TextCompletionResponse;
}
