<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\Backend;
use markhuot\craftai\validators\Json as JsonValidator;

class Replicate extends Backend
{
    protected array $defaultValues = [
        'settings' => [
            'baseUrl' => 'https://api.replicate.com/v1/',
        ],
    ];

    public function rules()
    {
        return array_merge(parent::rules(), [
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'token'], 'required'],
            ]]
        ]);
    }
}