<?php

use markhuot\craftpest\factories\Entry;

it('does not save embeddings for drafts', function () {
    $entry = Entry::factory()
        ->isDraft(true)
        ->create();

    dd($entry);
})->skip();
