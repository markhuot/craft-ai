<?php

use Craft;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craft\fields\PlainText;
use markhuot\craftai\fields\CodeComponent;
use markhuot\craftai\fields\CodeComponentPermissions;
use markhuot\craftai\fields\CodeComponentValue;
use markhuot\craftai\fields\UpdateCodeComponent;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    // Build fields directly through the Fields service — craft-pest's
    // Field::factory() rolls a random faker name and runs it through
    // is_callable() before our explicit ->name() override applies, which
    // makes the test flaky whenever the random word matches a global
    // function (e.g. Pest's `test()`).
    $codeField = new CodeComponent(['name' => 'Component', 'handle' => 'component']);
    expect(Craft::$app->getFields()->saveField($codeField))->toBeTrue();

    $plainField = new PlainText(['name' => 'Body', 'handle' => 'body']);
    expect(Craft::$app->getFields()->saveField($plainField))->toBeTrue();

    Section::factory()
        ->name('Pages')
        ->handle('pages')
        ->fields($codeField, $plainField)
        ->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpdateCodeComponent::class, cpOnly: true);
});

it('writes a single tab on a canonical entry', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();

    $output = $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
        'twig' => '<h1>Hello</h1>',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $reloaded = EntryElement::find()->id($entry->id)->status(null)->one();
    /** @var CodeComponentValue $value */
    $value = $reloaded->getFieldValue('component');
    expect($value->twig)->toBe('<h1>Hello</h1>');
    expect($value->css)->toBe('');
    expect($value->js)->toBe('');
});

it('writes multiple tabs in a single call', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();

    $output = $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
        'twig' => 'T', 'css' => 'C', 'js' => 'J',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $value = EntryElement::find()->id($entry->id)->status(null)->one()->getFieldValue('component');
    expect($value->twig)->toBe('T');
    expect($value->css)->toBe('C');
    expect($value->js)->toBe('J');
});

it('preserves untouched tabs across partial updates', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();
    $entry->setFieldValue('component', new CodeComponentValue([
        'twig' => 'KEEP-TWIG',
        'css' => 'KEEP-CSS',
        'js' => 'KEEP-JS',
        'element' => $entry,
    ]));
    Craft::$app->getElements()->saveElement($entry);

    $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
        'js' => 'NEW-JS',
    ]);

    $value = EntryElement::find()->id($entry->id)->status(null)->one()->getFieldValue('component');
    expect($value->twig)->toBe('KEEP-TWIG');
    expect($value->css)->toBe('KEEP-CSS');
    expect($value->js)->toBe('NEW-JS');
});

it('rejects an unknown fieldHandle', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();

    $output = $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'nonsense',
        'twig' => 'x',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('No field "nonsense"');
});

it('refuses to update a non-CodeComponent field', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();

    $output = $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'body',
        'twig' => 'x',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('not a CodeComponent');
});

it('errors when no tabs are supplied', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();

    $output = $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('at least one');
});

it('errors when neither entryId nor draftId is provided', function () {
    $output = $this->registry->execute('update_code_component', [
        'fieldHandle' => 'component',
        'twig' => 'x',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Validation failed');
});

it('updates a draft and leaves the canonical entry alone', function () {
    $entry = Entry::factory()->section('pages')->title('Home')->create();
    $draftId = Craft::$app->getDrafts()->createDraft($entry, $entry->authorId ?? 1)->draftId;

    $output = $this->registry->execute('update_code_component', [
        'draftId' => $draftId,
        'fieldHandle' => 'component',
        'twig' => 'DRAFTED',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $draft = EntryElement::find()->draftId($draftId)->status(null)->one();
    expect($draft->getFieldValue('component')->twig)->toBe('DRAFTED');

    $canonical = EntryElement::find()->id($entry->id)->status(null)->one();
    expect($canonical->getFieldValue('component')->twig)->toBe('');
});

it('denies tab writes the current user does not have permission for', function () {
    $entry = Entry::factory()->section('pages')->title('Home page')->create();

    // The tool's per-tab permission check runs *before* saveElement, so the
    // identity here only needs to be a non-admin User whose `can()` calls
    // return false for the tabs the agent tries to write. We override `can()`
    // via an anonymous subclass — no DB persistence needed (the early exit
    // means no FK-touching audit rows are ever inserted) and the assertion
    // exercises the same denial branch the agent would hit in production.
    $nonAdmin = new class extends User {
        /** @param array<string, mixed> $params */
        public function can($permissionName, array $params = []): bool
        {
            $permissionName = strtolower((string) $permissionName);
            // Grant only the tool-level permission + the JS tab.
            return $permissionName === strtolower('craftAi:useTool:update_code_component')
                || $permissionName === strtolower(CodeComponentPermissions::JS);
        }
    };
    $nonAdmin->id = 9001;
    $nonAdmin->admin = false;
    Craft::$app->getUser()->setIdentity($nonAdmin);

    $output = $this->registry->execute('update_code_component', [
        'entryId' => $entry->id,
        'fieldHandle' => 'component',
        'twig' => 'BLOCKED',
        'js' => 'OK',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('twig');
    expect($output->text)->not->toContain('js,'); // js was allowed; only twig should appear in the denial list

    // Confirm nothing was written despite the partial allow on js — denial
    // is all-or-nothing per call.
    $reloaded = EntryElement::find()->id($entry->id)->status(null)->one();
    expect($reloaded->getFieldValue('component')->js)->toBe('');
});
