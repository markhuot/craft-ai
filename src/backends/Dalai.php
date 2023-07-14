<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\Backend;
use markhuot\craftai\validators\Json as JsonValidator;

class Dalai extends Backend
{
    protected array $defaultValues = [
        'type' => self::class,
        'name' => 'Dalai',
        'settings' => ['baseUrl' => 'http://localhost:3000/'],
    ];

    /**
     * @return array<mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            ['settings', 'required'],
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl'], 'required'],
            ]],
        ]);
    }
}
