<?php

namespace markhuot\craftai\web;

class Controller extends \craft\web\Controller
{
    public function flash(?string $default = null, array $settings = []): self
    {
        $this->setSuccessFlash($default, $settings);

        return $this;
    }

    function response(...$types)
    {
        $data = null;
        if ($this->request->getAcceptsJson()) {
            $data = $types['json'] ?? null;
        }
        else {
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
