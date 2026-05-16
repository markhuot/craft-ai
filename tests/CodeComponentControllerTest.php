<?php

use Craft;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craft\fields\PlainText;
use markhuot\craftai\fields\CodeComponent;
use markhuot\craftai\fields\CodeComponentValue;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

function bootCodeComponentController(): array
{
    $admin = new User();
    $admin->id = 1;
    $admin->admin = true;
    $admin->username = 'test';
    $admin->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($admin);

    $code = new CodeComponent(['name' => 'Component', 'handle' => 'component']);
    expect(Craft::$app->getFields()->saveField($code))->toBeTrue();

    $plain = new PlainText(['name' => 'Body', 'handle' => 'body']);
    expect(Craft::$app->getFields()->saveField($plain))->toBeTrue();

    Section::factory()
        ->name('Pages')
        ->handle('pages')
        ->fields($code, $plain)
        ->create();

    $entry = Entry::factory()->section('pages')->title('Home page')->create();

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

    $response = test()->http('get', 'admin')
        ->addHeader('Accept', 'application/json')
        ->setBody([
            'action' => 'craft-ai/code-component/state',
            'entryId' => $entry->id,
            'fieldHandle' => 'component',
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->addHeader('Accept', 'application/json')
        ->setBody([
            'action' => 'craft-ai/code-component/persist-session',
            'entryId' => $entry->id,
            'fieldHandle' => 'component',
            'sessionId' => 'fresh-session-uuid',
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->addHeader('Accept', 'application/json')
        ->setBody([
            'action' => 'craft-ai/code-component/persist-session',
            'entryId' => $entry->id,
            'fieldHandle' => 'component',
            'sessionId' => 'new-session',
        ])
        ->send()
        ->assertOk();

    $value = EntryElement::find()->id($entry->id)->status(null)->one()->getFieldValue('component');
    expect($value->agentSessionId)->toBe('new-session');
});
