<?php

use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\features\EditText;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\TextEditResponse;
use markhuot\craftpest\factories\User;

beforeEach(fn () => Backend::fake());

it('can complete a prompt', function () {
    $user = User::factory()->admin(true);
    OpenAi::factory()->create();
    $prompt = 'Finish this sentence, Craft is great because...';

    $this->actingAs($user)
        ->get('admin/ai/text')
        ->dd()
        ->assertOk()
        ->form('#main-form')
        ->fill('content', $prompt)
        ->submit()
        ->followRedirects()
        ->assertOk()
        ->assertSee($prompt);
});

it('can edit a prompt', function () {
    OpenAi::factory()->create();

    $response = Backend::for(EditText::class)->editText(
        input: 'There onces was a man form Misisipi.',
        instruction: 'Fix grammar and misspellings',
    );

    expect($response)->toBeInstanceOf(TextEditResponse::class);
});
