<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Description;

/**
 * Retrieve a single content entry by its ID. Returns full entry details
 * including all custom field values, or an error if the entry is not found.
 */
class GetEntry extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Description('The entry ID to look up')]
        int $id,
    ): array|ToolOutput {
        $entry = Entry::find()->id($id)->status(null)->one();

        if ($entry === null) {
            return new ToolOutput("No entry found with ID {$id}.", isError: true);
        }

        return $entry->toArray();
    }
}
