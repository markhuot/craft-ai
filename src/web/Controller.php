<?php

namespace markhuot\craftai\web;

use markhuot\craftai\db\ActiveRecord;
use function markhuot\openai\helpers\throw_if;
use yii\base\InlineAction;
use yii\web\Response;

class Controller extends \craft\web\Controller
{
    /**
     * @param  InlineAction  $action
     * @param  array<mixed>  $params
     * @return array<mixed>
     */
    public function bindActionParams($action, $params): array
    {
        $method = new \ReflectionMethod($this, $action->actionMethod);
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (! isset($params[$name])) {
                continue;
            }

            $type = $param->getType();
            if (! $type) {
                continue;
            }

            if (! is_a($type, \ReflectionNamedType::class)) {
                continue;
            }

            $className = $type->getName();
            if (! class_exists($className) || ! is_subclass_of($className, ActiveRecord::class)) {
                continue;
            }

            $id = $params[$name];
            $model = $className::find()->where(['id' => $id])->one();
            throw_if(! $model, 'No model found for id ['.$id.']');

            $params[$name] = $model;
        }

        return parent::bindActionParams($action, $params);
    }

    /**
     * @param  array<array-key, string>  $settings Such as `icon` and `iconLabel`
     */
    public function flash(?string $default = null, array $settings = []): self
    {
        $this->setSuccessFlash($default, $settings);

        return $this;
    }

    /**
     * @param  array{html: mixed, json: mixed}  $types
     */
    public function response(...$types): Response
    {
        $data = null;
        if ($this->request->getAcceptsJson()) {
            $data = $types['json'] ?? null;
        } else {
            $data = $types['html'] ?? $types;
        }

        if (is_callable($data)) {
            $data = $data();
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson($data);
        }

        return $data;
    }
}
