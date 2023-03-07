<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\TextEditResponse;

interface EditText
{
    function editText(string $input, string $instruction): TextEditResponse;
}
