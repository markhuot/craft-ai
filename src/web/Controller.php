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
        if ($this->request->getAcceptsJson()) {
            $return = $types['json'] ?? null;
        }
        elseif (isset($types['html'])) {
            $return = $types['html'];
        }
        else {
            $return = $types;
        }

        return is_callable($return) ? $return() : $return;
    }
}
