<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\validators\Json as JsonValidator;

class StableDiffusion extends \markhuot\craftai\models\Backend
{
    public function rules()
    {
        return array_merge(parent::rules(), [
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'apiToken'], 'required'],
            ]]
        ]);
    }
}
