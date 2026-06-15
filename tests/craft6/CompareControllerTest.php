<?php

use CraftCms\Cms\Section\Enums\SectionType;

use Craft;
use craft\elements\User;
use CraftCms\Cms\Field\PlainText;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;

beforeEach(function () {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->loginByUserId((int) $user->id);

    $body = seedField('body', 'Body', PlainText::class);
    seedSection('posts', 'Posts', SectionType::Channel, [$body]);

    // The test queue connection runs jobs synchronously, so a pushed AgentJob
    // would execute inline — and running the narration job in-process during a
    // simulated request leaves the reused legacy app in a state where the next
    // request in the same test returns an empty response. The controller's job
    // is to enqueue narration, not run it, so fake the queue: pushes are
    // recorded but never executed, matching a real request where the worker
    // drains the job out of band. (Same pattern as SessionsControllerTest.)
    \Illuminate\Support\Facades\Queue::fake();

    // Stub the LLM so the queued narration job doesn't call out.
    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider
    {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

function compareEntryWithRevisions(): array
{
    $entry = seedEntry('posts', ['title' => 'Title A']);
    $entry->setFieldValue('body', 'alpha');
    Craft::$app->elements->saveElement($entry);
    $r1 = Craft::$app->getRevisions()->createRevision($entry, null, null, [], force: true);

    $entry->title = 'Title B';
    $entry->setFieldValue('body', 'beta');
    Craft::$app->elements->saveElement($entry);
    $r2 = Craft::$app->getRevisions()->createRevision($entry, null, null, [], force: true);

    return [$entry, $r1, $r2];
}

function postDiff(array $body)
{
    return test()->postJson('admin?action=craft-ai/compare/diff', $body);
}

it('lists current + revisions for the pickers, newest-first', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $response = test()->get('admin?action=craft-ai/compare/revisions&entryId='.$entry->id);

    $response->assertOk();
    $data = json_decode((string) $response->getContent(), true);
    $refs = collect($data['revisions'])->pluck('ref')->all();

    expect($refs[0])->toBe('current');
    expect($data['revisions'][0]['isCurrent'])->toBeTrue();
    expect($refs)->toContain('rev:'.$r2);
    expect($refs)->toContain('rev:'.$r1);
    // rev:$r2 (higher revisionNum) appears before rev:$r1
    expect(array_search('rev:'.$r2, $refs, true))->toBeLessThan(array_search('rev:'.$r1, $refs, true));
});

it('lists open drafts in the pickers and can diff a draft against current', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    // A saved (non-provisional) draft off the canonical entry, with changed
    // content so the diff is non-trivial.
    $draft = Craft::$app->getDrafts()->createDraft($entry, $entry->authorId ?? 1, 'Holiday copy');
    $draft->title = 'Title C';
    $draft->setFieldValue('body', 'gamma');
    Craft::$app->elements->saveElement($draft);

    // The picker advertises the draft, named, alongside current + revisions.
    $listing = test()->get('admin?action=craft-ai/compare/revisions&entryId='.$entry->id);
    $listing->assertOk();
    $options = json_decode((string) $listing->getContent(), true)['revisions'];
    $byRef = collect($options)->keyBy('ref');

    expect($byRef->has('draft:'.$draft->draftId))->toBeTrue();
    expect($byRef->get('draft:'.$draft->draftId)['label'])->toBe('Draft: Holiday copy');
    // Ordering: current, then drafts, then revisions.
    $refs = collect($options)->pluck('ref')->all();
    expect($refs[0])->toBe('current');
    expect(array_search('draft:'.$draft->draftId, $refs, true))
        ->toBeLessThan(array_search('rev:'.$r2, $refs, true));

    // And the draft is a usable side of an actual diff.
    $response = postDiff(['entryId' => $entry->id, 'a' => 'current', 'b' => 'draft:'.$draft->draftId]);
    $response->assertOk();
    $data = json_decode((string) $response->getContent(), true);
    expect($data['ok'])->toBeTrue();
    expect($data['b']['ref'])->toBe('draft:'.$draft->draftId);
    expect($data['html'])->toContain('gamma');
});

it('omits provisional (autosave) drafts from the pickers', function () {
    [$entry] = compareEntryWithRevisions();

    // Provisional drafts are Craft's per-user autosave scratch space behind the
    // entry-edit screen — transient editing state, not a deliberate version.
    $provisional = Craft::$app->getDrafts()->createDraft(
        $entry,
        $entry->authorId ?? 1,
        null,
        null,
        [],
        provisional: true,
    );

    $listing = test()->get('admin?action=craft-ai/compare/revisions&entryId='.$entry->id);
    $listing->assertOk();
    $refs = collect(json_decode((string) $listing->getContent(), true)['revisions'])->pluck('ref')->all();

    expect($refs)->not->toContain('draft:'.$provisional->draftId);
});

it('renders the compare page', function () {
    [$entry] = compareEntryWithRevisions();

    $response = test()->get('admin?action=craft-ai/compare/index&entryId='.$entry->id);

    $response->assertOk();
    expect((string) $response->getContent())->toContain('data-craftai-compare-root');
    expect((string) $response->getContent())->toContain('data-craftai-compare-bootstrap');
});

it('breadcrumbs back to the entry being compared', function () {
    [$entry] = compareEntryWithRevisions();

    $response = test()->get('admin?action=craft-ai/compare/index&entryId='.$entry->id);

    $response->assertOk();
    // The CP breadcrumb gives the user a way back to the original entry.
    // Craft 6 renders the entry chip with a root-relative href, while
    // getCpEditUrl() returns the absolute form — compare on the path so the
    // assertion stays meaningful without pinning the scheme/host.
    $editUrl = (string) $entry->getCpEditUrl();
    expect($editUrl)->not->toBe('');
    $editPath = parse_url($editUrl, PHP_URL_PATH) ?: $editUrl;
    expect($editPath)->not->toBe('');
    expect((string) $response->getContent())->toContain($editPath);
});

it('computes a diff, persists an artifact, and kicks off narration', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $response = postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2]);

    $response->assertOk();
    $data = json_decode((string) $response->getContent(), true);

    expect($data['ok'])->toBeTrue();
    expect(strtolower($data['html']))->toContain('<!doctype html');
    expect($data['html'])->toContain('beta');
    expect($data['summary']['changed'])->toBeGreaterThanOrEqual(2);
    expect($data['sessionId'])->toBeString();
    expect($data['artifactUrl'])->toContain('ai/artifacts/'.$data['artifactId']);

    $artifact = ArtifactRecord::findOne(['id' => $data['artifactId']]);
    expect($artifact)->not->toBeNull();
    expect($artifact->entryId)->toBe($entry->id);
    expect($artifact->html)->toContain('beta');

    $session = SessionRecord::findOne(['id' => $data['sessionId']]);
    expect($session)->not->toBeNull();
    expect($session->toolMode)->toBe('readonly');
    expect($session->clientType)->toBe('cp');

    $systemNote = MessageRecord::find()->where(['sessionId' => $data['sessionId'], 'role' => 'system'])->one();
    expect($systemNote)->not->toBeNull();
    expect($systemNote->content)->toContain('ai-compare-revisions');

    $userMessage = MessageRecord::find()->where(['sessionId' => $data['sessionId'], 'role' => 'user'])->one();
    expect($userMessage)->not->toBeNull();
});

it('reuses the session + artifact when the same comparison is reopened, without re-narrating', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $first = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2])->getContent(), true);
    $second = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2])->getContent(), true);

    // Two immutable revisions → a permanent cache hit: same session + artifact.
    expect($second['reused'])->toBeTrue();
    expect($second['sessionId'])->toBe($first['sessionId']);
    expect($second['artifactId'])->toBe($first['artifactId']);

    // Only one narration was ever kicked off — the second open didn't append a
    // second prompt to the session.
    $userMessages = MessageRecord::find()
        ->where(['sessionId' => $first['sessionId'], 'role' => 'user'])
        ->count();
    expect((int) $userMessages)->toBe(1);

    // Exactly one comparison row memoizes the pair.
    expect((int) \markhuot\craftai\records\ComparisonRecord::find()->count())->toBe(1);

    // The memoized narration session is shareable (null owner) so a second
    // editor reopening the comparison can read it too.
    $session = SessionRecord::findOne(['id' => $first['sessionId']]);
    expect($session->userId)->toBeNull();
});

it('diffs an older revision against current without error', function () {
    // Regression: viewing "revision N → current" 500'd in production because
    // CompareController::actionDiff memoizes into craftai_comparisons on every
    // diff, and that table was absent from the running schema. This exercises
    // the exact surface action (older revision vs current) and proves the memo
    // row is written — i.e. the table the controller depends on exists and is
    // writable, not just that the PHP diff computes.
    [$entry, $r1] = compareEntryWithRevisions();

    $response = postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'current']);

    $response->assertOk();
    $data = json_decode((string) $response->getContent(), true);
    expect($data['ok'])->toBeTrue();
    expect(strtolower($data['html']))->toContain('<!doctype html');

    $memoized = \markhuot\craftai\records\ComparisonRecord::find()
        ->where(['entryId' => $entry->id, 'aRef' => 'rev:'.$r1, 'bRef' => 'current'])
        ->count();
    expect((int) $memoized)->toBe(1);
});

it('mints a separate session for a different revision pair', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $first = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2])->getContent(), true);
    $second = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'current'])->getContent(), true);

    expect($second['reused'])->toBeFalse();
    expect($second['sessionId'])->not->toBe($first['sessionId']);
});

it('400s when a version ref cannot be resolved', function () {
    $this->withoutExceptionHandling();
    [$entry] = compareEntryWithRevisions();

    $threw = false;
    try {
        postDiff(['entryId' => $entry->id, 'a' => 'rev:999999', 'b' => 'current']);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});
