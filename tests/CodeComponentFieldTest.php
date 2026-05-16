<?php

use Craft;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use markhuot\craftai\fields\CodeComponent;
use markhuot\craftai\fields\CodeComponentPermissions;
use markhuot\craftai\fields\CodeComponentValue;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

function makeCodeComponentField(string $handle = 'component'): CodeComponent
{
    // craft-pest's Field::factory() generates a random faker name and then
    // runs it through is_callable() before our `->name()` override applies,
    // so any random word that matches a global function (e.g. Pest's
    // `test()`) blows up. Build the field directly to keep these tests
    // deterministic.
    $field = new CodeComponent([
        'name' => ucfirst($handle),
        'handle' => $handle,
    ]);
    expect(Craft::$app->getFields()->saveField($field))->toBeTrue();

    return $field;
}

function makeCodeComponentSection(string $handle = 'pages'): EntryElement
{
    $field = makeCodeComponentField();

    Section::factory()
        ->name(ucfirst($handle))
        ->handle($handle)
        ->fields($field)
        ->create();

    // Note: avoid a single-word title that happens to match a global
    // function name. craft-pest's `resolveDefinition()` runs every
    // attribute value through `is_callable()`, and PHP matches function
    // names case-insensitively — so `'Test'` collides with Pest's `test()`.
    return Entry::factory()->section($handle)->title('A test entry')->create();
}

it('registers the CodeComponent field type with Craft', function () {
    $types = Craft::$app->getFields()->getAllFieldTypes();

    expect($types)->toContain(CodeComponent::class);
});

it('round-trips twig/css/js through normalize and serialize', function () {
    $field = new CodeComponent(['handle' => 'component']);

    $stored = $field->serializeValue(
        $field->normalizeValue([
            'twig' => '<h1>{{ entry.title }}</h1>',
            'css' => 'h1 { color: red; }',
            'js' => 'console.log("hi");',
        ], null),
        null,
    );

    expect($stored)->toBe([
        'twig' => '<h1>{{ entry.title }}</h1>',
        'css' => 'h1 { color: red; }',
        'js' => 'console.log("hi");',
        'agentSessionId' => null,
    ]);
});

it('normalizes a JSON string back into a CodeComponentValue', function () {
    $field = new CodeComponent(['handle' => 'component']);

    $value = $field->normalizeValue(
        json_encode(['twig' => 'A', 'css' => 'B', 'js' => 'C']),
        null,
    );

    expect($value)->toBeInstanceOf(CodeComponentValue::class);
    expect($value->twig)->toBe('A');
    expect($value->css)->toBe('B');
    expect($value->js)->toBe('C');
});

it('normalizes empty / missing values to empty strings', function () {
    $field = new CodeComponent(['handle' => 'component']);

    $value = $field->normalizeValue(null, null);

    expect($value)->toBeInstanceOf(CodeComponentValue::class);
    expect($value->twig)->toBe('');
    expect($value->css)->toBe('');
    expect($value->js)->toBe('');
});

it('renders the value with rendered twig + style + script blocks', function () {
    $entry = makeCodeComponentSection();
    $entry->setFieldValue('component', new CodeComponentValue([
        'twig' => '<h1>{{ entry.title }}</h1>',
        'css' => 'h1 { color: red; }',
        'js' => 'console.log("hi");',
        'element' => $entry,
    ]));

    /** @var CodeComponentValue $value */
    $value = $entry->getFieldValue('component');
    $html = (string) $value->render();

    expect($html)->toContain('<h1>A test entry</h1>');
    expect($html)->toContain('<style>h1 { color: red; }</style>');
    expect($html)->toContain('<script>console.log("hi");</script>');
});

it('omits empty sections from rendered output', function () {
    $value = new CodeComponentValue(['css' => 'p { margin: 0 }']);
    $html = (string) $value->render();

    expect($html)->toBe('<style>p { margin: 0 }</style>');
});

it('round-trips a saved entry through the database with all three tabs', function () {
    $entry = makeCodeComponentSection('articles');
    $entry->setFieldValue('component', new CodeComponentValue([
        'twig' => 'TWIG',
        'css' => 'CSS',
        'js' => 'JS',
        'element' => $entry,
    ]));

    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $fresh = EntryElement::find()->id($entry->id)->status(null)->one();
    /** @var CodeComponentValue $reloaded */
    $reloaded = $fresh->getFieldValue('component');

    expect($reloaded)->toBeInstanceOf(CodeComponentValue::class);
    expect($reloaded->twig)->toBe('TWIG');
    expect($reloaded->css)->toBe('CSS');
    expect($reloaded->js)->toBe('JS');
});

it('grants all tabs to an admin user via the permission resolver', function () {
    $admin = new User();
    $admin->id = 1;
    $admin->admin = true;

    $perms = CodeComponentPermissions::resolve($admin);

    expect($perms)->toBe(['twig' => true, 'css' => true, 'js' => true, 'prompt' => true]);
});

it('returns no tab permissions for a guest (null identity)', function () {
    $perms = CodeComponentPermissions::resolve(null);

    expect($perms)->toBe(['twig' => false, 'css' => false, 'js' => false, 'prompt' => false]);
});
