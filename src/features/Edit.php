<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\TextEditResponse;

interface Edit
{
    function editText(string $input, string $instruction): TextEditResponse;
}
