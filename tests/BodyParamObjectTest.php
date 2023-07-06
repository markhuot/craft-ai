<?php

use craft\elements\Entry;
use craft\web\Request;
use markhuot\craftai\behaviors\BodyParamObjectBehavior;
use yii\web\BadRequestHttpException;

it('throws when trying to get body params on a GET', function () {
    $this->expectException(BadRequestHttpException::class);

    $request = new Request();
    $request->attachBehaviors([BodyParamObjectBehavior::class]);
    $request->getBodyParamObject(Entry::class);
});

it('converts request data to value objects', function () {
    $request = new Request();
    $request->headers->add('X-Http-Method-Override', 'POST');
    $request->attachBehaviors([BodyParamObjectBehavior::class]);
    $dataObject = new class extends \craft\base\Model {
        public $name;
        public function rules(): array
        {
            return [
                ['name', 'required'],
            ];
        }
    };

    $data = $request->getBodyParamObject($dataObject);
    expect($data->getErrors())->toHaveKeys(['name']);
});
