<?php

use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\features\Edit;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\TextCompletionResponse;
use markhuot\craftai\models\TextEditResponse;
use markhuot\craftpest\factories\User;
use function markhuot\craftpest\helpers\test\mock;

it('can complete a prompt', function () {
    $this->actingAs(User::factory()->admin(true));

    mock(OpenAi::class)
        ->makePartial()
        ->expects('completeText')
        ->andReturn(new TextCompletionResponse([
            'text' => 'This is a test',
        ]));

    $backend = new OpenAi;
    $backend->type = OpenAi::class;
    $backend->name = 'OpenAI Backend';
    $backend->settings = ['baseUrl' => 'https://api.openai.com/v1/', 'apiKey' => '$OPENAI_API_KEY'];
    $backend->save();

    $this->get('admin/ai/text')
        ->assertOk()
        ->form('#main-form')
        ->fill('content', 'Finish this sentence, Craft is great because...')
        ->submit()
        ->followRedirects()
        ->assertOk()
        ->assertSee('This is a test');
});

it('can edit a prompt', function () {
    mock(OpenAi::class)
        ->makePartial()
        ->expects('editText')
        ->andReturn(new TextEditResponse([
            'text' => 'There once was a man from Mississippi.',
        ]));

    OpenAi::factory()->create();

    $response = Backend::for(Edit::class)->editText(
        input: 'There onces was a man form Misisipi.',
        instruction: 'Fix grammar and misspellings',
    );

    expect($response)->text->toBe('There once was a man from Mississippi.');
});
