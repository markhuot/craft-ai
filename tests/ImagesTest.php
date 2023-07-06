<?php

use markhuot\craftpest\factories\User;

it('generates images', function () {
    $user = User::factory()->admin(true)->create();
    $volume = \markhuot\craftpest\factories\Volume::factory()->create();

    $this->actingAs($user)->action('ai/image/store-generation', [
        'prompt' => 'two otters holding hands',
        'volume' => $volume->id,
    ])
        ->assertRedirect();
});
