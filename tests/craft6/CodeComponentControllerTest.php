<?php

use Craft;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\Section\Enums\SectionType;
use markhuot\craftai\fields\CodeComponent;
use markhuot\craftai\fields\CodeComponentValue;

function bootCodeComponentController(): array
{
    $admin = new User();
    $admin->id = 1;
    $admin->admin = true;
    $admin->username = 'test';
    $admin->email = 'test@example.com';
    Craft::$app->getUser()->loginByUserId((int) $admin->id);

    $code = new CodeComponent(['name' => 'Component', 'handle' => 'component']);
    expect(Craft::$app->getFields()->saveField($code))->toBeTrue();

    $plain = new PlainText(['name' => 'Body', 'handle' => 'body']);
    expect(Craft::$app->getFields()->saveField($plain))->toBeTrue();

    seedSection('pages', 'Pages', SectionType::Channel, [$code, $plain]);

    $entry = seedEntry('pages', ['title' => 'Home page']);

    return [$code, $entry];
}

it('returns the current persisted tab values for an entry', function () {
    [$field, $entry] = bootCodeComponentController();

    $entry->setFieldValue('component', new CodeComponentValue([
        'twig' => '<h1>Hi</h1>',
        'css' => 'h1 { color: red; }',
        'js' => '',
        'agentSessionId' => 'sess-1',
        'element' => $entry,
    ]));
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $response = test()->getJson('admin?action=craft-ai/code-component/state&entryId='.$entry->id.'&fieldHandle=component');

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBe([
        'twig' => '<h1>Hi</h1>',
        'css' => 'h1 { color: red; }',
        'js' => '',
        'agentSessionId' => 'sess-1',
    ]);
});

it('persists a newly minted session id without disturbing the other tabs', function () {
    [$field, $entry] = bootCodeComponentController();

    $entry->setFieldValue('component', new CodeComponentValue([
        'twig' => 'KEEP-TWIG',
        'css' => 'KEEP-CSS',
        'js' => 'KEEP-JS',
        'agentSessionId' => null,
        'element' => $entry,
    ]));
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $response = test()->postJson('admin?action=craft-ai/code-component/persist-session', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
        'sessionId' => 'fresh-session-uuid',
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toBe(['ok' => true]);

    $reloaded = EntryElement::find()->id($entry->id)->status(null)->one();
    /** @var CodeComponentValue $value */
    $value = $reloaded->getFieldValue('component');
    expect($value->twig)->toBe('KEEP-TWIG');
    expect($value->css)->toBe('KEEP-CSS');
    expect($value->js)->toBe('KEEP-JS');
    expect($value->agentSessionId)->toBe('fresh-session-uuid');
});

it('updates the existing session id when called a second time on the same field', function () {
    [$field, $entry] = bootCodeComponentController();

    $entry->setFieldValue('component', new CodeComponentValue([
        'twig' => '',
        'css' => '',
        'js' => '',
        'agentSessionId' => 'old-session',
        'element' => $entry,
    ]));
    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    test()->postJson('admin?action=craft-ai/code-component/persist-session', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
        'sessionId' => 'new-session',
    ])->assertOk();

    $value = EntryElement::find()->id($entry->id)->status(null)->one()->getFieldValue('component');
    expect($value->agentSessionId)->toBe('new-session');
});

it('only exposes update_code_component on sessions tagged code-component-field', function () {
    $registry = \markhuot\craftai\Plugin::getInstance()->getToolRegistry();
    $all = $registry->descriptors();

    $names = static fn (array $list): array => array_map(static fn ($d) => $d->name, $list);

    $fieldSurface = $registry->filterByClient($all, \markhuot\craftai\agent\ClientType::CODE_COMPONENT_FIELD);
    $cpSurface = $registry->filterByClient($all, \markhuot\craftai\agent\ClientType::CP);
    $widgetSurface = $registry->filterByClient($all, \markhuot\craftai\agent\ClientType::WIDGET);
    $mcpSurface = $registry->filterByClient($all, \markhuot\craftai\agent\ClientType::MCP);

    expect($names($fieldSurface))->toContain('update_code_component');
    expect($names($cpSurface))->not->toContain('update_code_component');
    expect($names($widgetSurface))->not->toContain('update_code_component');
    expect($names($mcpSurface))->not->toContain('update_code_component');
});
