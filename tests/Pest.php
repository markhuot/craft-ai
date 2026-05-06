<?php

use craft\helpers\FileHelper;
use markhuot\craftai\tools\ToolOutput;

uses(Tests\TestCase::class)->in('./');

function decode(ToolOutput $output): array
{
    return json_decode($output->text, true);
}

function writeTemplate(string $base, string $relative, string $contents): void
{
    $path = $base.'/'.ltrim($relative, '/');
    FileHelper::createDirectory(dirname($path));
    file_put_contents($path, $contents);
}
