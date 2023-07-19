<?php

namespace markhuot\craftai\behaviors;

use Craft;
use craft\helpers\App;
use craft\web\Request;
use craft\web\Response;
use markhuot\craftai\db\ActiveRecord;
use yii\base\ExitException;
use yii\web\HttpException;
use function markhuot\openai\helpers\throw_if;
use function markhuot\openai\helpers\web\app;
use yii\base\Behavior;
use yii\web\BadRequestHttpException;

/**
 * @property Request $owner;
 */
class BodyParamObjectBehavior extends Behavior
{
    public function getQueryParamString(string $name, string $defaultValue = ''): string
    {
        $value = $this->owner->getQueryParam($name, $defaultValue);
        throw_if(! is_string($value), 'Could not convert ['.$name.'] to a string');

        return $value;
    }

    /**
     * @template T of \craft\base\Model|ActiveRecord
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function getBodyParamObject(string $class, string $formName = '')
    {
        if (! $this->owner->getIsPost()) {
            throw new BadRequestHttpException('Post request required');
        }

        $bodyParams = $this->owner->getBodyParams();

        if (is_subclass_of($class, ActiveRecord::class)) {
            $key = $bodyParams[$class::$keyField] ?? null;
            if ($key) {
                /** @var T $model */
                $model = $class::find()->where([$class::$keyField => $key])->one();
            }

            if (empty($model)) {
                /** @var T $model */
                $model = $class::make(array_filter([
                    $class::$polymorphicKeyField => $bodyParams[$class::$polymorphicKeyField] ?? null,
                ]));
            }
        } else {
            $model = new $class;
        }

        $model->load($bodyParams, $formName);

        if (! $model->validate()) {
            if (App::env('YII_ENV_TEST')) {
                // This should be cleaned up. Craft really should allow me to throw an
                // exception that can be a redirect. Then Pest would handle all of this for me and I wouldn't have
                // this conditional. I would always return the exception and pest would either handle the exception
                // and render HTML or throw the exception if it's called ->withoutExceptionHandling, but that's
                // not possible today so we're going to ignore it and come back to it later.
                // @phpstan-ignore-next-line
                if (function_exists('test') && test()->shouldSkipExceptionHandling() && ! empty($model->errors)) {
                    throw new \RuntimeException(collect($model->errors)->flatten()->join(' '));
                }
            }
            else {
                $this->owner->getAcceptsJson() ?
                    $this->errorJson($model) :
                    $this->errorBack($model);
            }
        }

        return $model;
    }

    public function errorJson(\yii\base\Model $model): never
    {
        $response = new Response();
        $response->setStatusCode(500);
        $response->headers->add('content-type', 'application/json');
        $response->content = json_encode([
            'errors' => $model->errors,
        ], JSON_THROW_ON_ERROR);
        Craft::$app->end(500, $response);
        exit; // in most cases Craft::$app->end will terminate, but if we're in test mode or something, we'll terminate here
    }

    public function errorBack(\yii\base\Model $model): never
    {
        foreach ($model->errors as $key => $messages) {
            app()->getSession()->setFlash('error.'.$key, implode(',', $messages));
        }

        $this->setOldFlashes(app()->getRequest()->getBodyParams());

        $response = new Response();
        $response->setStatusCode(302);
        $response->headers->add('Location', app()->getRequest()->getUrl());
        Craft::$app->end(500, $response);
        exit; // in most cases Craft::$app->end will terminate, but if we're in test mode or something, we'll terminate here
    }

    /**
     * @param  array<mixed>  $array
     */
    protected function setOldFlashes(array $array, string $prefix = ''): void
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $this->setOldFlashes($value, implode('.', array_filter([$prefix, $key])));
            } else {
                app()->getSession()->setFlash('old.'.implode('.', array_filter([$prefix, $key])), $value);
            }
        }
    }
}
