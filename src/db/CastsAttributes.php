<?php

namespace markhuot\craftai\db;

trait CastsAttributes
{
    protected array $casts = [];

    function __get($key)
    {
        if ($caster = ($this->casts[$key] ?? false)) {
            return (new $caster)->get($this, $key, $this->getAttribute($key));
        }

        return parent::__get($key);
    }

    function setAttributes($values, $safeOnly = true): void
    {
        foreach ($values as $key => $value) {
            if ($caster = ($this->casts[$key] ?? false)) {
                $values[$key] = (new $caster)->set($this, $key, $value);
            }
        }

        parent::setAttributes($values, $safeOnly);
    }
}
