<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\TextEditResponse;

interface EditText
{
    public function editText(string $input, string $instruction): TextEditResponse;
}
