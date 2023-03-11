<?php

use markhuot\craftpest\factories\User;

it('can create a backend', function () {
    $user = User::factory()->admin(true);
    
    $this->actingAs($user)
        ->get('ai/backends')
        ->assertSee('Add a new AI backend');
});