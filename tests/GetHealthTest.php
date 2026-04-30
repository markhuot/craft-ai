<?php

use markhuot\craftai\tools\GetHealth;

it('returns a healthy status sentence including the Craft version', function () {
    $tool = new GetHealth();

    $text = $tool();

    expect($text)->toContain('Craft CMS');
    expect($text)->toContain('operational');
    expect($text)->toContain(Craft::$app->getVersion());
});
