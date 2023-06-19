<?php

namespace markhuot\craftai\db;

use markhuot\craftai\casts\CastInterface;

trait CastsAttributes
{
    /** @var array<string, class-string<CastInterface>> */
    protected array $casts = [];

    public function __get($key)
    {
        if ($caster = ($this->casts[$key] ?? false)) {
            return (new $caster)->get($this, $key, $this->getAttribute($key));
        }

        return parent::__get($key);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach ($values as $key => $value) {
            if ($caster = ($this->casts[$key] ?? false)) {
                $values[$key] = (new $caster)->set($this, $key, $value);
            }
        }

        parent::setAttributes($values, $safeOnly);
    }
}
