<?php

use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolOutput;

it('returns a healthy status sentence including the Craft version', function () {
    $tool = new GetHealth();

    $output = $tool();

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeFalse();
    expect($output->text)->toContain('Craft CMS');
    expect($output->text)->toContain('operational');
    expect($output->text)->toContain(Craft::$app->getVersion());
});
