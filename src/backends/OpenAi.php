<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\features\Completion;
use markhuot\craftai\features\Edit;
use markhuot\craftai\validators\Json as JsonValidator;

class OpenAi extends \markhuot\craftai\models\Backend implements Completion, Edit
{
    use OpenAiCompletion;
    use OpenAiTextEdit;

    // protected array $defaultValues = [
    //     'settings.baseUrl' => 'https://api.openai.com/v1/',
    // ];

    public function rules()
    {
        return array_merge(parent::rules(), [
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'apiKey'], 'required'],
            ]]
        ]);
    }
}
