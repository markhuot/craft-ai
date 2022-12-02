<?php

namespace markhuot\craftai\validators;

use yii\base\DynamicModel;
use yii\validators\Validator;

class Json extends Validator
{
    public array $rules;

    function validateAttribute($model, $attribute)
    {
        $value = $model->$attribute;
        $dynamicModel = new DynamicModel($value);
        foreach ($this->rules as $rule) {
            $dynamicModel->addRule($rule[0], $rule[1], array_slice($rule, 2));
        }
        if (!$dynamicModel->validate()) {
            foreach ($dynamicModel->errors as $key => $error) {
                $this->addError($model, $attribute . '.' .$key, $error[0], [
                    'value' => $dynamicModel->$key,
                ]);
            }
        }
    }
}
