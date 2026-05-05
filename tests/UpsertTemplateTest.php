<?php

use craft\helpers\FileHelper;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertTemplate;

beforeEach(function () {
    $this->tempTemplatesPath = sys_get_temp_dir().'/craftai-templates-'.bin2hex(random_bytes(8));
    FileHelper::createDirectory($this->tempTemplatesPath);
    $this->tempTemplatesPath = realpath($this->tempTemplatesPath);

    $this->outsideDir = sys_get_temp_dir().'/craftai-outside-'.bin2hex(random_bytes(8));
    FileHelper::createDirectory($this->outsideDir);
    $this->outsideDir = realpath($this->outsideDir);

    $this->originalTemplatesAlias = Craft::getAlias('@templates');
    Craft::setAlias('@templates', $this->tempTemplatesPath);
    Craft::$app->getView()->setTemplatesPath($this->tempTemplatesPath);

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertTemplate::class);
});

afterEach(function () {
    Craft::setAlias('@templates', $this->originalTemplatesAlias);
    Craft::$app->getView()->setTemplatesPath($this->originalTemplatesAlias);

    if (is_dir($this->tempTemplatesPath)) {
        FileHelper::removeDirectory($this->tempTemplatesPath);
    }
    if (isset($this->outsideDir) && is_dir($this->outsideDir)) {
        FileHelper::removeDirectory($this->outsideDir);
    }
});

it('creates a new template at the given path', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'index.twig',
        'contents' => '<h1>Hi</h1>',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['path'])->toBe('index.twig');
    expect($payload['size'])->toBe(11);
    expect($payload['created'])->toBeTrue();
    expect(file_get_contents($this->tempTemplatesPath.'/index.twig'))->toBe('<h1>Hi</h1>');
});

it('overwrites an existing template', function () {
    file_put_contents($this->tempTemplatesPath.'/index.twig', 'old');

    $output = $this->registry->execute('upsert_template', [
        'path' => 'index.twig',
        'contents' => 'new',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['created'])->toBeFalse();
    expect(file_get_contents($this->tempTemplatesPath.'/index.twig'))->toBe('new');
});

it('creates intermediate directories', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'blog/post.twig',
        'contents' => 'POST',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect(is_dir($this->tempTemplatesPath.'/blog'))->toBeTrue();
    expect(file_get_contents($this->tempTemplatesPath.'/blog/post.twig'))->toBe('POST');
});

it('rejects an absolute path', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => '/etc/passwd',
        'contents' => 'pwned',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('relative');
});

it('rejects a path that escapes the templates root via ..', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => '../escape.twig',
        'contents' => 'nope',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('..');
    expect(is_file(dirname($this->tempTemplatesPath).'/escape.twig'))->toBeFalse();
});

it('rejects a nested path that escapes the templates root via ..', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'blog/../../escape.twig',
        'contents' => 'nope',
    ]);

    expect($output->isError)->toBeTrue();
});

it('rejects a path with a disallowed extension', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'config.json',
        'contents' => '{}',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('twig');
});

it('rejects a path without an extension', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'README',
        'contents' => 'hi',
    ]);

    expect($output->isError)->toBeTrue();
});

it('rejects an empty path', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => '',
        'contents' => 'hi',
    ]);

    expect($output->isError)->toBeTrue();
});

it('accepts a .html template', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'mail/confirmation.html',
        'contents' => '<p>Confirmed</p>',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect(file_get_contents($this->tempTemplatesPath.'/mail/confirmation.html'))->toBe('<p>Confirmed</p>');
});

it('writes empty contents when explicitly given', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'empty.twig',
        'contents' => '',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['size'])->toBe(0);
    expect(file_get_contents($this->tempTemplatesPath.'/empty.twig'))->toBe('');
});

it('rejects a path containing a null byte', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => "evil\0.twig",
        'contents' => 'pwned',
    ]);

    expect($output->isError)->toBeTrue();
    expect(is_file($this->tempTemplatesPath.'/evil'))->toBeFalse();
    expect(is_file($this->tempTemplatesPath.'/evil.twig'))->toBeFalse();
});

it('rejects a Windows-style absolute path', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'C:\\Windows\\evil.twig',
        'contents' => 'nope',
    ]);

    expect($output->isError)->toBeTrue();
});

it('rejects a Windows-style backslash root path', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => '\\evil.twig',
        'contents' => 'nope',
    ]);

    expect($output->isError)->toBeTrue();
});

it('refuses to overwrite a symlink that lives inside the templates directory', function () {
    file_put_contents($this->outsideDir.'/secret.txt', 'original');
    symlink($this->outsideDir.'/secret.txt', $this->tempTemplatesPath.'/evil.twig');

    $output = $this->registry->execute('upsert_template', [
        'path' => 'evil.twig',
        'contents' => 'pwned',
    ]);

    expect($output->isError)->toBeTrue();
    expect(file_get_contents($this->outsideDir.'/secret.txt'))->toBe('original');
});

it('refuses to write through a symlinked directory that escapes the root', function () {
    symlink($this->outsideDir, $this->tempTemplatesPath.'/escape');

    $output = $this->registry->execute('upsert_template', [
        'path' => 'escape/evil.twig',
        'contents' => 'pwned',
    ]);

    expect($output->isError)->toBeTrue();
    expect(is_file($this->outsideDir.'/evil.twig'))->toBeFalse();
});

it('returns the resolved absolutePath inside the templates root', function () {
    $output = $this->registry->execute('upsert_template', [
        'path' => 'blog/post.twig',
        'contents' => 'POST',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['absolutePath'])->toStartWith($this->tempTemplatesPath.DIRECTORY_SEPARATOR);
    expect(realpath($payload['absolutePath']))->toBe($payload['absolutePath']);
});
