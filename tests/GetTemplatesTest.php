<?php

use craft\helpers\FileHelper;
use markhuot\craftai\tools\GetTemplates;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->tempTemplatesPath = sys_get_temp_dir().'/craftai-templates-'.bin2hex(random_bytes(8));
    FileHelper::createDirectory($this->tempTemplatesPath);

    $this->originalTemplatesAlias = Craft::getAlias('@templates');
    Craft::setAlias('@templates', $this->tempTemplatesPath);
    Craft::$app->getView()->setTemplatesPath($this->tempTemplatesPath);

    $this->registry = new ToolRegistry();
    $this->registry->register(GetTemplates::class);
});

afterEach(function () {
    Craft::setAlias('@templates', $this->originalTemplatesAlias);
    Craft::$app->getView()->setTemplatesPath($this->originalTemplatesAlias);

    if (is_dir($this->tempTemplatesPath)) {
        FileHelper::removeDirectory($this->tempTemplatesPath);
    }
});

function writeTemplate(string $base, string $relative, string $contents): void
{
    $path = $base.'/'.ltrim($relative, '/');
    FileHelper::createDirectory(dirname($path));
    file_put_contents($path, $contents);
}

it('lists templates in the site templates directory', function () {
    writeTemplate($this->tempTemplatesPath, 'index.twig', '<h1>Home</h1>');
    writeTemplate($this->tempTemplatesPath, 'blog/post.twig', '<article>Post</article>');
    writeTemplate($this->tempTemplatesPath, 'blog/index.html', '<ul></ul>');

    $output = $this->registry->execute('get_templates', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $paths = array_column($payload, 'path');
    expect($paths)->toContain('index.twig');
    expect($paths)->toContain('blog/post.twig');
    expect($paths)->toContain('blog/index.html');
});

it('returns each template with absolutePath and size', function () {
    writeTemplate($this->tempTemplatesPath, 'index.twig', 'Hello');

    $output = $this->registry->execute('get_templates', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveCount(1);
    expect($payload[0])->toHaveKeys(['path', 'absolutePath', 'size']);
    expect($payload[0]['path'])->toBe('index.twig');
    expect($payload[0]['size'])->toBe(5);
    expect($payload[0]['absolutePath'])->toEndWith('index.twig');
});

it('skips files with non-template extensions', function () {
    writeTemplate($this->tempTemplatesPath, 'index.twig', 'a');
    writeTemplate($this->tempTemplatesPath, 'README.md', 'b');
    writeTemplate($this->tempTemplatesPath, 'config.json', 'c');

    $output = $this->registry->execute('get_templates', []);

    $paths = array_column(json_decode($output->text, true), 'path');
    expect($paths)->toBe(['index.twig']);
});

it('skips hidden files and node_modules', function () {
    writeTemplate($this->tempTemplatesPath, 'index.twig', 'a');
    writeTemplate($this->tempTemplatesPath, '.hidden.twig', 'b');
    writeTemplate($this->tempTemplatesPath, 'node_modules/pkg/template.twig', 'c');

    $output = $this->registry->execute('get_templates', []);

    $paths = array_column(json_decode($output->text, true), 'path');
    expect($paths)->toBe(['index.twig']);
});

it('filters by path prefix', function () {
    writeTemplate($this->tempTemplatesPath, 'index.twig', 'a');
    writeTemplate($this->tempTemplatesPath, 'blog/post.twig', 'b');
    writeTemplate($this->tempTemplatesPath, 'blog/index.twig', 'c');
    writeTemplate($this->tempTemplatesPath, 'shop/cart.twig', 'd');

    $output = $this->registry->execute('get_templates', ['prefix' => 'blog/']);

    $paths = array_column(json_decode($output->text, true), 'path');
    sort($paths);
    expect($paths)->toBe(['blog/index.twig', 'blog/post.twig']);
});

it('returns an empty array when the templates directory is empty', function () {
    $output = $this->registry->execute('get_templates', []);

    expect($output->isError)->toBeFalse($output->text);
    expect(json_decode($output->text, true))->toBe([]);
});

it('returns results sorted by path', function () {
    writeTemplate($this->tempTemplatesPath, 'zebra.twig', 'z');
    writeTemplate($this->tempTemplatesPath, 'alpha.twig', 'a');
    writeTemplate($this->tempTemplatesPath, 'mango/fruit.twig', 'm');

    $output = $this->registry->execute('get_templates', []);

    $paths = array_column(json_decode($output->text, true), 'path');
    expect($paths)->toBe(['alpha.twig', 'mango/fruit.twig', 'zebra.twig']);
});
