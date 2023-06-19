<?php

namespace markhuot\craftai\web;

use yii\web\Response;

class Controller extends \craft\web\Controller
{
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
