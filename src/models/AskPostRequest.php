<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use craft\models\Volume;

class AskPostRequest extends Model
{
    public string $prompt;
    public function rules(): array
    {
        return [
            [['prompt'], 'required'],
        ];
    }
}
