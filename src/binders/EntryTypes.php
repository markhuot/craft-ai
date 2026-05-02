<?php

namespace markhuot\craftai\binders;

/**
 * Binds a list of entry type handles or IDs into a list of EntryType models.
 * Skips any values that cannot be resolved — pair with ExistingEntryType
 * validation per item if you need strict guarantees.
 */
class EntryTypes implements Binder
{
    /**
     * @return list<\craft\models\EntryType>
     */
    public function bind(mixed $value, array $arguments): array
    {
        if (! is_array($value)) {
            return [];
        }

        $binder = new EntryType();
        $resolved = [];
        foreach ($value as $item) {
            $entryType = $binder->bind($item, $arguments);
            if ($entryType instanceof \craft\models\EntryType) {
                $resolved[] = $entryType;
            }
        }

        return $resolved;
    }
}
