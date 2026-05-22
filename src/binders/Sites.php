<?php

namespace markhuot\craftai\binders;

/**
 * Binds a list of site handles or IDs into a list of Site models.
 * Skips any values that cannot be resolved — pair with ExistingSites
 * validation per item if you need strict guarantees.
 */
class Sites implements Binder
{
    /**
     * @return list<\craft\models\Site>|null
     */
    public function bind(mixed $value, array $arguments): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return [];
        }

        $binder = new Site();
        $resolved = [];
        foreach ($value as $item) {
            $site = $binder->bind($item, $arguments);
            if ($site instanceof \craft\models\Site) {
                $resolved[] = $site;
            }
        }

        return $resolved;
    }
}
