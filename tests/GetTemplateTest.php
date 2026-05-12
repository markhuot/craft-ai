<?php

use craft\helpers\FileHelper;
use markhuot\craftai\tools\GetTemplate;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->tempTemplatesPath = sys_get_temp_dir().'/craftai-templates-'.bin2hex(random_bytes(8));
    FileHelper::createDirectory($this->tempTemplatesPath);

    $this->originalTemplatesAlias = Craft::getAlias('@templates');
    $this->originalTemplatesPath = Craft::$app->getView()->getTemplatesPath();
    Craft::setAlias('@templates', $this->tempTemplatesPath);
    Craft::$app->getView()->setTemplatesPath($this->tempTemplatesPath);

    $this->registry = new ToolRegistry();
    $this->registry->register(GetTemplate::class);
});

afterEach(function () {
    Craft::setAlias('@templates', $this->originalTemplatesAlias);
    Craft::$app->getView()->setTemplatesPath($this->originalTemplatesPath);

    if (is_dir($this->tempTemplatesPath)) {
        FileHelper::removeDirectory($this->tempTemplatesPath);
    }
});

it('returns the contents of a template by full path', function () {
    writeTemplate($this->tempTemplatesPath, 'blog/post.twig', '<article>{{ entry.title }}</article>');

    $output = $this->registry->execute('get_template', ['path' => 'blog/post.twig']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    expect($payload['data']['contents'])->toBe('<article>{{ entry.title }}</article>');
    expect($payload['data']['path'])->toBe('blog/post.twig');
    expect($payload['data']['absolutePath'])->toEndWith('blog/post.twig');
});

it('resolves a bare template name without an extension', function () {
    writeTemplate($this->tempTemplatesPath, 'blog/post.twig', 'POST CONTENT');

    $output = $this->registry->execute('get_template', ['path' => 'blog/post']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['contents'])->toBe('POST CONTENT');
    expect($payload['data']['absolutePath'])->toEndWith('blog/post.twig');
});

it('resolves a directory name to its index template', function () {
    writeTemplate($this->tempTemplatesPath, 'blog/index.twig', 'INDEX CONTENT');

    $output = $this->registry->execute('get_template', ['path' => 'blog']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['contents'])->toBe('INDEX CONTENT');
    expect($payload['data']['absolutePath'])->toEndWith('blog/index.twig');
});

it('returns an error for a missing template', function () {
    $output = $this->registry->execute('get_template', ['path' => 'does/not/exist.twig']);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('does/not/exist.twig');
});

it('rejects an empty path', function () {
    $output = $this->registry->execute('get_template', ['path' => '']);

    expect($output->isError)->toBeTrue();
});
