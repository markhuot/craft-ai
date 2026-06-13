<?php

use markhuot\craftai\tools\GetHealth;

it('returns a wrapped payload describing the Craft version and status', function () {
    $tool = new GetHealth();

    $payload = $tool();

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toContain('Craft CMS');
    expect($payload['_notes'])->toContain('operational');
    expect($payload['_notes'])->toContain(Craft::$app->getVersion());

    expect($payload['data']['craftVersion'])->toBe(Craft::$app->getVersion());
    expect($payload['data']['status'])->toBe('ok');
    expect($payload['data']['message'])->toContain('Craft CMS');
    expect($payload['data']['message'])->toContain('operational');
});
