<?php

use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\features\EditText;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\TextCompletionResponse;
use markhuot\craftai\models\TextEditResponse;
use markhuot\craftpest\factories\User;
use function markhuot\craftpest\helpers\test\mock;

beforeEach(fn () => Backend::fake());

it('can complete a prompt', function () {
    OpenAi::factory()->create();
    $prompt = 'Finish this sentence, Craft is great because...';

    $this->actingAs(User::factory()->admin(true))
        ->get('/admin/ai/text')
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
