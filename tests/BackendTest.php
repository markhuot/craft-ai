<?php

use markhuot\craftai\backends\HuggingFace;
use markhuot\craftai\backends\OpenAi;
use markhuot\craftai\backends\StableDiffusion;
use markhuot\craftai\models\Backend;
use markhuot\craftpest\factories\User;

it('shows backends', function () {
    $user = User::factory()->admin(true);

    $this->actingAs($user)
        ->get('admin/ai/backends')
        ->assertSee('Add a new AI backend');
});

it('fails with a missing backend info', function ($backendClass) {
    $backend = new $backendClass;
    $backend->save();

    expect($backend)
        ->name->not->toBeEmpty()
        ->settings->baseUrl->not->toBeEmpty()
        ->getErrors()
        ->toHaveKeys(['settings.apiKey']);
})->with([
    'OpenAi' => OpenAi::class,
    'HuggingFace' => HuggingFace::class,
    'StableDiffusion' => StableDiffusion::class,
]);

it('saves new backends', function () {
    $backend = new OpenAi;
    $backend->settings = [...$backend->settings, 'apiKey' => '$OPENAI_API_KEY'];
    $backend->save();

    expect($backend)
        ->errors->toBeEmpty();
});

it('saves a new backend')
    ->actingAsAdmin()
    ->get('admin/ai/backend/create/stable-diffusion')
    ->assertOk()
    ->form('#main-form')
    ->addField('settings[baseUrl]', 'http://...')
    ->addField('settings[apiKey]', '$STABLE_DIFFUSION_API_KEY')
    ->submit()
    ->followRedirects()
    ->assertOk();

it('saves an existing backend', function () {
    $backend = OpenAi::factory()->create();
    $backendData = $backend->toArray();

    $this->actingAsAdmin()
        ->withoutExceptionHandling()
        ->action('ai/backend/store', [
            ...$backendData,
            'settings' => [
                ...$backendData['settings'],
                'baseUrl' => 'foo bar',
            ],
        ]);

    expect($backend->fresh())->settings->baseUrl->toBe('foo bar');
});

it('processes backend errors', function () {
    $this->expectException(RuntimeException::class);

    $backend = new OpenAi;
    $backend->settings = [...$backend->settings, 'apiKey' => '$OPENAI_API_KEY'];
    $backend->save();

    $backend->generateImage('ERROR');
});
