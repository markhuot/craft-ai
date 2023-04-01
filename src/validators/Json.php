<?php

namespace markhuot\craftai\validators;

use yii\base\DynamicModel;
use yii\validators\Validator;

class Json extends Validator
{
    /**
     * @var array<array-key, array{array<string>|string, string}>
     */
    public array $rules;

    public function validateAttribute($model, $attribute): void
    {
        // Get "null" props to start. If there's a rule like `['foo', 'required']` then we need
        // to make sure the `foo` prop is set to null so that the validator can __get('foo')
        // without throwing errors.
        $props = collect($this->rules)
            ->pluck(0)
            ->flatten()
            ->mapWithKeys(fn ($k) => [$k => null])
            ->toArray();
        $value = [...$props, ...$model->$attribute];

        $dynamicModel = new DynamicModel($value);
        foreach ($this->rules as $rule) {
            $dynamicModel->addRule($rule[0], $rule[1], array_slice($rule, 2));
        }

        if (! $dynamicModel->validate()) {
            foreach ($dynamicModel->errors as $key => $error) {
                $model->addError($attribute.'.'.$key, $error[0]);
            }
        }
    }
}
