<?php

use Craft;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use craft\models\Volume;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\validators\AssetSettingsValidation;
use markhuot\craftai\validators\AvailableFieldType;
use markhuot\craftai\validators\ExistingAsset;
use markhuot\craftai\validators\ExistingComment;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingEntryTypes;
use markhuot\craftai\validators\ExistingField;
use markhuot\craftai\validators\ExistingSection;
use markhuot\craftai\validators\ExistingSite;
use markhuot\craftai\validators\ExistingSites;
use markhuot\craftai\validators\ExistingTemplate;
use markhuot\craftai\validators\ExistingVolume;
use markhuot\craftai\validators\ValidatesBoundParameters;
use markhuot\craftai\validators\ValidatesUnboundParameters;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\EntryType as EntryTypeFactory;
use markhuot\craftpest\factories\Field as FieldFactory;
use markhuot\craftpest\factories\Section as SectionFactory;
use markhuot\craftpest\factories\Volume as VolumeFactory;
use yii\base\Model;
use yii\validators\Validator;

/**
 * Tiny throwaway model used to host a single attribute under test. All the
 * craft-ai validators read from `$model->{$attribute}` and write errors back
 * via `$model->addError()`, so we don't need the full Yii rule plumbing —
 * just a scratch model + the attribute name.
 */
function makeValidatorModel(mixed $value, array $extraAttrs = []): Model
{
    $model = new class extends Model
    {
        public mixed $value = null;

        public mixed $section = null;

        public mixed $id = null;

        public mixed $sites = null;
    };
    $model->value = $value;
    foreach ($extraAttrs as $key => $val) {
        $model->{$key} = $val;
    }

    return $model;
}

/**
 * Run a single validator against `value` and return the resulting error
 * messages on that attribute. Empty array means the value passed.
 *
 * @return list<string>
 */
function runValidator(Validator $validator, mixed $value, array $extraAttrs = []): array
{
    $model = makeValidatorModel($value, $extraAttrs);
    $validator->validateAttribute($model, 'value');

    return $model->getErrors('value');
}

// region ExistingEntry / ExistingDraft / ExistingAsset / ExistingComment ──

it('ExistingEntry accepts a known entry ID and rejects unknown / non-numeric values', function () {
    SectionFactory::factory()->name('Posts')->handle('posts')->create();
    $entry = EntryFactory::factory()->section('posts')->title('Hello')->create();

    expect(runValidator(new ExistingEntry(), (int) $entry->id))->toBe([]);
    expect(runValidator(new ExistingEntry(), (string) $entry->id))->toBe([]);

    $missing = runValidator(new ExistingEntry(), 99999);
    expect($missing)->not->toBeEmpty();
    expect($missing[0])->toContain('No entry found');

    $badType = runValidator(new ExistingEntry(), 'not-a-number');
    expect($badType)->not->toBeEmpty();
    expect($badType[0])->toContain('numeric');
});

it('ExistingEntry has skipOnEmpty=true so Yii drops it from rule chains on null', function () {
    // `skipOnEmpty` is honored by Yii's rule plumbing (Model::validate), not by
    // `validateAttribute` directly — calling validateAttribute always runs the
    // body. The test here pins the property so we don't lose the
    // optional-parameter behavior the dispatcher relies on.
    $v = new ExistingEntry();
    expect($v->skipOnEmpty)->toBeTrue();
});

it('ExistingDraft accepts a real draftId and rejects unknown / wrong-type values', function () {
    SectionFactory::factory()->name('Posts')->handle('posts')->create();
    $entry = EntryFactory::factory()->section('posts')->title('source')->create();
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    expect(runValidator(new ExistingDraft(), (int) $draft->draftId))->toBe([]);

    $missing = runValidator(new ExistingDraft(), 99999);
    expect($missing[0])->toContain('No draft found');

    expect(runValidator(new ExistingDraft(), 'not-numeric')[0])->toContain('numeric');
});

it('ExistingAsset rejects unknown ids and bad scalar types', function () {
    // We don't drive the upsert path here (that has its own coverage and is
    // sensitive to local volume + filesystem setup); we just verify the
    // validator wires up the not-found branch and the type guard.
    expect(runValidator(new ExistingAsset(), 99999)[0])->toContain('No asset found');
    expect(runValidator(new ExistingAsset(), 'abc')[0])->toContain('numeric');
    expect(runValidator(new ExistingAsset(), true)[0])->toContain('numeric');
});

it('ExistingComment accepts a real comment row and rejects unknown ids', function () {
    $record = new CommentRecord();
    $record->sessionId = 'validator-comment';
    $record->elementId = 1;
    $record->isDraft = false;
    $record->body = 'note';
    $record->status = CommentRecord::STATUS_OPEN;
    $record->save();

    expect(runValidator(new ExistingComment(), (int) $record->id))->toBe([]);
    expect(runValidator(new ExistingComment(), 99999)[0])->toContain('No comment found');
    expect(runValidator(new ExistingComment(), 'abc')[0])->toContain('comment ID');
});

// region ExistingSection / ExistingSite / ExistingVolume ────────────────────

it('ExistingSection accepts handles, numeric IDs, and Section instances', function () {
    $section = SectionFactory::factory()->name('News')->handle('news')->create();

    expect(runValidator(new ExistingSection(), 'news'))->toBe([]);
    expect(runValidator(new ExistingSection(), (int) $section->id))->toBe([]);
    expect(runValidator(new ExistingSection(), (string) $section->id))->toBe([]);

    expect(runValidator(new ExistingSection(), 'not-a-section')[0])->toContain('No section found');
    expect(runValidator(new ExistingSection(), 99999)[0])->toContain('No section found');
});

it('ExistingSection in the bound phase requires a non-null id on the Section instance', function () {
    $bare = new Section(); // no id
    $errors = runValidator(new ExistingSection(), $bare);
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('missing an ID');

    // The bound-phase validator is opt-in via the marker interface; verify it's
    // wired to the bound contract so the Tool runner picks it up there too.
    expect(new ExistingSection())->toBeInstanceOf(ValidatesBoundParameters::class);
    expect(new ExistingSection())->toBeInstanceOf(ValidatesUnboundParameters::class);
});

it('ExistingSite accepts the built-in primary site handle', function () {
    $site = Craft::$app->sites->getPrimarySite();

    expect(runValidator(new ExistingSite(), $site->handle))->toBe([]);
    expect(runValidator(new ExistingSite(), (int) $site->id))->toBe([]);

    expect(runValidator(new ExistingSite(), 'nope-not-a-site')[0])->toContain('No site found');
});

it('ExistingSite in the bound phase requires a non-null id on the Site instance', function () {
    $bare = new Site();
    $errors = runValidator(new ExistingSite(), $bare);
    expect($errors[0])->toContain('missing an ID');
});

it('ExistingVolume accepts handles and IDs and rejects unknown values', function () {
    $volume = VolumeFactory::factory()->name('Photos')->handle('photos')->create();

    expect(runValidator(new ExistingVolume(), 'photos'))->toBe([]);
    expect(runValidator(new ExistingVolume(), (int) $volume->id))->toBe([]);

    expect(runValidator(new ExistingVolume(), 'no-such-volume')[0])->toContain('No volume found');
    expect(runValidator(new ExistingVolume(), 99999)[0])->toContain('No volume found');
});

it('ExistingVolume rejects a Volume model with no ID in the bound phase', function () {
    $bare = new Volume();
    $errors = runValidator(new ExistingVolume(), $bare);
    expect($errors[0])->toContain('missing an ID');
});

// region ExistingEntryType / ExistingField ──────────────────────────────────

it('ExistingEntryType accepts handles and IDs (no inSection)', function () {
    $section = SectionFactory::factory()->name('Posts')->handle('posts')->create();
    $type = $section->getEntryTypes()[0];

    expect(runValidator(new ExistingEntryType(), $type->handle))->toBe([]);
    expect(runValidator(new ExistingEntryType(), (int) $type->id))->toBe([]);

    expect(runValidator(new ExistingEntryType(), 'not-a-type')[0])->toContain('No entry type found');
});

it('ExistingEntryType scoped to a section rejects types from a sibling section', function () {
    $a = SectionFactory::factory()->name('SectionA')->handle('secA')->create();
    $b = SectionFactory::factory()->name('SectionB')->handle('secB')->create();

    $aType = $a->getEntryTypes()[0];

    $validator = new ExistingEntryType();
    $validator->inSection = 'section';

    // aType belongs to secA; scoping to secB should fail.
    $model = makeValidatorModel($aType->handle, ['section' => 'secB']);
    $validator->validateAttribute($model, 'value');
    $errors = $model->getErrors('value');
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('No entry type "');

    // And scoping to secA should pass.
    $model2 = makeValidatorModel($aType->handle, ['section' => 'secA']);
    $validator->validateAttribute($model2, 'value');
    expect($model2->getErrors('value'))->toBe([]);
});

it('ExistingEntryType rejects an EntryType model with no id (bound phase)', function () {
    $bare = new EntryType();
    $errors = runValidator(new ExistingEntryType(), $bare);
    expect($errors[0])->toContain('missing an ID');
});

it('ExistingField accepts handles, UIDs, and IDs', function () {
    $field = FieldFactory::factory()->name('Body')->handle('bodyText')->type(PlainText::class)->create();

    expect(runValidator(new ExistingField(), 'bodyText'))->toBe([]);
    expect(runValidator(new ExistingField(), $field->uid))->toBe([]);
    expect(runValidator(new ExistingField(), (int) $field->id))->toBe([]);

    expect(runValidator(new ExistingField(), 'no-such-field')[0])->toContain('No field found');
    expect(runValidator(new ExistingField(), 99999)[0])->toContain('No field found');
});

it('ExistingField with uidOnly enforces UUID format and rejects handles', function () {
    $field = FieldFactory::factory()->name('Title2')->handle('title2')->type(PlainText::class)->create();

    $validator = new ExistingField();
    $validator->uidOnly = true;

    expect(runValidator($validator, $field->uid))->toBe([]);

    // The handle passes the non-uidOnly path but uidOnly rejects with a
    // UUID-format error rather than a "no field" error — verify we're
    // hitting the right branch.
    $errors = runValidator($validator, 'title2');
    expect($errors[0])->toContain('UUID');
});

// region ExistingTemplate ──────────────────────────────────────────────────

it('ExistingTemplate accepts a real site template and rejects missing ones', function () {
    $tempPath = sys_get_temp_dir().'/craftai-validator-templates-'.bin2hex(random_bytes(4));
    FileHelper::createDirectory($tempPath);

    $originalAlias = Craft::getAlias('@templates');
    $originalPath = Craft::$app->getView()->getTemplatesPath();
    Craft::setAlias('@templates', $tempPath);
    Craft::$app->getView()->setTemplatesPath($tempPath);

    try {
        writeTemplate($tempPath, 'pages/about.twig', '<h1>About</h1>');

        expect(runValidator(new ExistingTemplate(), 'pages/about.twig'))->toBe([]);
        // Craft resolves bare names to the .twig variant.
        expect(runValidator(new ExistingTemplate(), 'pages/about'))->toBe([]);
        expect(runValidator(new ExistingTemplate(), 'pages/does-not-exist')[0])->toContain('No template found');
        expect(runValidator(new ExistingTemplate(), '')[0])->toContain('non-empty');
    } finally {
        Craft::setAlias('@templates', $originalAlias);
        Craft::$app->getView()->setTemplatesPath($originalPath);
        FileHelper::removeDirectory($tempPath);
    }
});

// region AvailableFieldType ─────────────────────────────────────────────────

it('AvailableFieldType accepts an installed field type FQCN and rejects unknown classes', function () {
    expect(runValidator(new AvailableFieldType(), PlainText::class))->toBe([]);

    $errors = runValidator(new AvailableFieldType(), 'made\\up\\Field');
    expect($errors[0])->toContain('not an installed field type');

    $badType = runValidator(new AvailableFieldType(), 12345);
    expect($badType[0])->toContain('field type class name');
});

// region ExistingEntryTypes / ExistingSites (list variants) ─────────────────

it('ExistingEntryTypes accepts a non-empty list of valid handles and rejects empties', function () {
    $section = SectionFactory::factory()->name('Posts')->handle('posts')->create();
    $type = $section->getEntryTypes()[0];

    expect(runValidator(new ExistingEntryTypes(), [$type->handle]))->toBe([]);
    expect(runValidator(new ExistingEntryTypes(), [(int) $type->id]))->toBe([]);

    // Mixed list with one bad value fails on the bad one.
    $errors = runValidator(new ExistingEntryTypes(), [$type->handle, 'no-such-type']);
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('No entry type found matching "no-such-type"');

    // Empty list is rejected — at least one type is required.
    expect(runValidator(new ExistingEntryTypes(), [])[0])->toContain('non-empty list');

    // Wrong scalar inside the list — short-circuits with a type error.
    expect(runValidator(new ExistingEntryTypes(), [true])[0])->toContain('string handles or numeric IDs');

    // Null is allowed (omitted parameter on update).
    expect(runValidator(new ExistingEntryTypes(), null))->toBe([]);
});

it('ExistingSites accepts a list of valid site handles and rejects empties', function () {
    $site = Craft::$app->sites->getPrimarySite();

    expect(runValidator(new ExistingSites(), [$site->handle]))->toBe([]);
    expect(runValidator(new ExistingSites(), [(int) $site->id]))->toBe([]);

    $errors = runValidator(new ExistingSites(), [$site->handle, 'phantom']);
    expect($errors[0])->toContain('No site found matching "phantom"');

    expect(runValidator(new ExistingSites(), [])[0])->toContain('non-empty list');
    expect(runValidator(new ExistingSites(), null))->toBe([]);
});

// region AssetSettingsValidation (bound phase) ──────────────────────────────

it('AssetSettingsValidation requires a defaultUploadLocationSource when restrictLocation is false', function () {
    $volume = VolumeFactory::factory()->name('Files')->handle('files')->create();

    // Missing the location key entirely — rejected.
    $errors = runValidator(new AssetSettingsValidation(), []);
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('defaultUploadLocationSource');

    // Valid volume key — passes.
    $errors = runValidator(new AssetSettingsValidation(), [
        'defaultUploadLocationSource' => 'volume:'.$volume->uid,
    ]);
    expect($errors)->toBe([]);

    // Malformed key (no colon) — rejected with a format error.
    $errors = runValidator(new AssetSettingsValidation(), [
        'defaultUploadLocationSource' => 'just-the-uid',
    ]);
    expect($errors[0])->toContain('volume source key in the form');

    // Key references a volume uid that doesn't exist.
    $errors = runValidator(new AssetSettingsValidation(), [
        'defaultUploadLocationSource' => 'volume:00000000-0000-0000-0000-000000000000',
    ]);
    expect($errors[0])->toContain('does not exist');
});

it('AssetSettingsValidation switches keys when restrictLocation is true', function () {
    $volume = VolumeFactory::factory()->name('Restricted')->handle('restricted')->create();

    // With restrictLocation=true, the validator looks at restrictedLocationSource
    // instead of defaultUploadLocationSource. Missing → fails with the right key.
    $errors = runValidator(new AssetSettingsValidation(), [
        'restrictLocation' => true,
    ]);
    expect($errors[0])->toContain('restrictedLocationSource');

    // Valid restrictedLocationSource → passes.
    $errors = runValidator(new AssetSettingsValidation(), [
        'restrictLocation' => true,
        'restrictedLocationSource' => 'volume:'.$volume->uid,
    ]);
    expect($errors)->toBe([]);
});

// region Marker interfaces ─────────────────────────────────────────────────

it('marker interfaces are implemented by the validators the Tool dispatcher needs', function () {
    // Sanity check: the Tool runner reads phase membership via instanceof on
    // these marker interfaces. If a validator is moved between phases by
    // mistake, the dispatcher silently runs it in the wrong slot — these
    // assertions pin the current contract so a regression shows up here.
    expect(new ExistingEntryType())->toBeInstanceOf(ValidatesUnboundParameters::class);
    expect(new ExistingEntryType())->toBeInstanceOf(ValidatesBoundParameters::class);
    expect(new ExistingField())->toBeInstanceOf(ValidatesUnboundParameters::class);
    expect(new ExistingField())->toBeInstanceOf(ValidatesBoundParameters::class);
    expect(new ExistingSection())->toBeInstanceOf(ValidatesBoundParameters::class);
    expect(new ExistingSite())->toBeInstanceOf(ValidatesBoundParameters::class);
    expect(new ExistingVolume())->toBeInstanceOf(ValidatesBoundParameters::class);
    expect(new ExistingEntryTypes())->toBeInstanceOf(ValidatesUnboundParameters::class);
    expect(new ExistingSites())->toBeInstanceOf(ValidatesUnboundParameters::class);
    expect(new AvailableFieldType())->toBeInstanceOf(ValidatesUnboundParameters::class);
    expect(new AssetSettingsValidation())->toBeInstanceOf(ValidatesBoundParameters::class);
});
