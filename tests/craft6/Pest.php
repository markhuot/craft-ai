<?php

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use markhuot\craftai\tools\ToolOutput;

uses(Tests\TestCase::class)->in('.');

function decode(ToolOutput $output): array
{
    return json_decode($output->text, true);
}

/**
 * Create a Section with the native Craft 6 factory. craft-pest's fluent
 * `Section::factory()->name()->handle()->type()->create()` becomes a single
 * attribute array; `type` defaults to Channel (as it did in craft-pest).
 */
function seedSection(string $handle, string $name, \CraftCms\Cms\Section\Enums\SectionType $type = \CraftCms\Cms\Section\Enums\SectionType::Channel, array $fields = [], bool $hasUrls = true): \CraftCms\Cms\Section\Models\Section
{
    $section = \CraftCms\Cms\Section\Models\Section::factory()->create([
        'name' => $name,
        'handle' => $handle,
        'type' => $type,
    ]);

    // craft-pest sections shipped with a usable entry type (title field + content
    // tab); native Section::factory() leaves a section with none until an entry is
    // created. Attach one — with a field layout containing $fields when the test
    // passed `->fields(...)` — so tools that read entry types / set field values
    // behave the same.
    if ($fields !== []) {
        $elements = array_map(static fn ($field) => [
            'uid' => (string) \CraftCms\Cms\Support\Str::uuid(),
            'type' => \CraftCms\Cms\FieldLayout\LayoutElements\CustomField::class,
            'fieldUid' => $field->uid,
            'required' => false,
        ], $fields);

        $layout = \CraftCms\Cms\FieldLayout\Models\FieldLayout::factory()
            ->withContentTab($elements)
            ->create();

        $entryType = \CraftCms\Cms\Entry\Models\EntryType::factory()
            ->create(['name' => $name, 'handle' => $handle, 'fieldLayoutId' => $layout->id]);
    } else {
        $entryType = \CraftCms\Cms\Entry\Models\EntryType::factory()
            ->withFieldLayout()
            ->create(['name' => $name, 'handle' => $handle]);
    }

    $section->entryTypes()->attach($entryType, ['sortOrder' => 1]);

    // Normalise the site settings to a VALID state. The native factory leaves
    // hasUrls true with a null uriFormat, which Craft 6 rejects on any later
    // re-save ("uri format is required when has urls is true"). Give URL-bearing
    // sections a real uriFormat; otherwise turn URLs off.
    \CraftCms\Cms\Section\Models\SectionSiteSettings::query()
        ->where('sectionId', $section->id)
        ->update($hasUrls
            ? ['hasUrls' => true, 'uriFormat' => '{slug}', 'template' => '_entry']
            : ['hasUrls' => false, 'uriFormat' => null, 'template' => null]);

    \CraftCms\Cms\Support\Facades\Fields::refreshFields();
    \CraftCms\Cms\Support\Facades\EntryTypes::refreshEntryTypes();

    return $section;
}

/**
 * Create a custom Field with the native factory and refresh Craft's field
 * cache so services/tools (Fields::getAllFields() et al.) see it immediately —
 * the raw factory insert leaves the cache stale. $type is a field-type class
 * (defaults to PlainText), matching craft-pest's `->type(PlainText::class)`.
 */
function seedField(string $handle, string $name, string $type = \CraftCms\Cms\Field\PlainText::class, array $attrs = []): \CraftCms\Cms\Field\Models\Field
{
    $field = \CraftCms\Cms\Field\Models\Field::factory()->create(array_merge([
        'name' => $name,
        'handle' => $handle,
        'type' => $type,
    ], $attrs));

    \CraftCms\Cms\Support\Facades\Fields::refreshFields();

    return $field;
}

/**
 * Create entries in an existing section (resolved by handle, the way
 * craft-pest's `Entry::factory()->section('handle')` worked) and return the
 * created EntryElement. Supported $attrs: title, slug, postDate (+ any other
 * native create() attribute). `enabled => false` disables the entry. Pass
 * $count > 1 to create several (returns the last one).
 */
function seedEntry(string $sectionHandle, array $attrs = [], int $count = 1): \CraftCms\Cms\Entry\Elements\Entry
{
    // forSection() needs the Eloquent Section MODEL, not the Data\Section DTO
    // that the Entries service returns, so resolve it directly.
    $section = \CraftCms\Cms\Section\Models\Section::query()
        ->where('handle', $sectionHandle)
        ->firstOrFail();

    $base = \CraftCms\Cms\Entry\Models\Entry::factory()->forSection($section);

    // Reuse the section's existing entry type (seedSection attaches one) rather
    // than letting EntryFactory mint a fresh type per entry.
    $entryType = $section->entryTypes()->first();
    if ($entryType !== null) {
        $base = $base->forEntryType($entryType);
    }

    // title/slug are applied through factory state (not raw create() attributes)
    // so the entry is correctly named even when we re-fetch it below.
    if (array_key_exists('title', $attrs)) {
        $base = $base->title($attrs['title']);
        unset($attrs['title']);
    }
    if (array_key_exists('slug', $attrs)) {
        $base = $base->slug($attrs['slug']);
        unset($attrs['slug']);
    }
    if (array_key_exists('enabled', $attrs)) {
        if ($attrs['enabled'] === false) {
            $base = $base->disabled();
        }
        unset($attrs['enabled']);
    }

    $last = null;
    for ($i = 0; $i < $count; $i++) {
        $model = $base->create($attrs);
        // status(null) so disabled entries resolve too (the factory's own
        // createElement() uses a default query that drops them, returning null).
        $last = \CraftCms\Cms\Entry\Elements\Entry::find()->id($model->id)->status(null)->one();
    }

    return $last;
}

function writeTemplate(string $base, string $relative, string $contents): void
{
    $path = $base.'/'.ltrim($relative, '/');
    FileHelper::createDirectory(dirname($path));
    file_put_contents($path, $contents);
}

/**
 * Insert a non-admin user via direct SQL and return its element ID. Used in
 * cross-user authorization tests where we need a real foreign userId on a
 * SessionRecord/CommentRecord but don't want the cost of running the full
 * Craft user save pipeline. Each call uses a fresh random suffix so multiple
 * users can coexist in a single test.
 */
function createOtherUser(string $labelPrefix = 'other'): int
{
    $suffix = bin2hex(random_bytes(4));
    $db = Craft::$app->getDb();
    $elementsTable = $db->getSchema()->getRawTableName('{{%elements}}');
    $usersTable = $db->getSchema()->getRawTableName('{{%users}}');

    $db->createCommand()->insert($elementsTable, [
        'type' => User::class,
        'enabled' => true,
        'archived' => false,
        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();
    $otherId = (int) $db->getLastInsertID();

    $db->createCommand()->insert($usersTable, [
        'id' => $otherId,
        'username' => $labelPrefix.'-'.$suffix,
        'email' => $labelPrefix.'-'.$suffix.'@example.com',
        'active' => true,
        'pending' => false,
        'locked' => false,
        'suspended' => false,
        'admin' => false,
        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
    ])->execute();

    return $otherId;
}
