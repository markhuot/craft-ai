<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use craft\models\Volume;

class ChatMessagePostRequest extends Model
{
    public string $message;
    public string $personality;

    public function rules(): array
    {
        return [
            [['personality'], 'required'],
            [['message'], 'required'],
        ];
    }
}
