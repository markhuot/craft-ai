<?php

use craft\fields\PlainText;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $body = Field::factory()->name('Body')->handle('body')->type(PlainText::class);
    Section::factory()->name('Posts')->handle('posts')->fields($body)->create();

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
    $entry = Entry::factory()->section('posts')->title('Title A')->create();
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
    return test()->http('post', 'admin')
        ->withCsrfToken()
        ->addHeader('Accept', 'application/json')
        ->setBody(['action' => 'craft-ai/compare/diff', ...$body])
        ->send();
}

it('lists current + revisions for the pickers, newest-first', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $response = test()->get('admin?action=craft-ai/compare/revisions&entryId='.$entry->id);

    $response->assertOk();
    $data = json_decode((string) $response->content, true);
    $refs = collect($data['revisions'])->pluck('ref')->all();

    expect($refs[0])->toBe('current');
    expect($data['revisions'][0]['isCurrent'])->toBeTrue();
    expect($refs)->toContain('rev:'.$r2);
    expect($refs)->toContain('rev:'.$r1);
    // rev:$r2 (higher revisionNum) appears before rev:$r1
    expect(array_search('rev:'.$r2, $refs, true))->toBeLessThan(array_search('rev:'.$r1, $refs, true));
});

it('renders the compare page', function () {
    [$entry] = compareEntryWithRevisions();

    $response = test()->get('admin?action=craft-ai/compare/index&entryId='.$entry->id);

    $response->assertOk();
    expect((string) $response->content)->toContain('data-craftai-compare-root');
    expect((string) $response->content)->toContain('data-craftai-compare-bootstrap');
});

it('breadcrumbs back to the entry being compared', function () {
    [$entry] = compareEntryWithRevisions();

    $response = test()->get('admin?action=craft-ai/compare/index&entryId='.$entry->id);

    $response->assertOk();
    // The CP breadcrumb gives the user a way back to the original entry.
    $editUrl = (string) $entry->getCpEditUrl();
    expect($editUrl)->not->toBe('');
    expect((string) $response->content)->toContain($editUrl);
});

it('computes a diff, persists an artifact, and kicks off narration', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $response = postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2]);

    $response->assertOk();
    $data = json_decode((string) $response->content, true);

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

    $first = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2])->content, true);
    $second = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2])->content, true);

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
    $data = json_decode((string) $response->content, true);
    expect($data['ok'])->toBeTrue();
    expect(strtolower($data['html']))->toContain('<!doctype html');

    $memoized = \markhuot\craftai\records\ComparisonRecord::find()
        ->where(['entryId' => $entry->id, 'aRef' => 'rev:'.$r1, 'bRef' => 'current'])
        ->count();
    expect((int) $memoized)->toBe(1);
});

it('mints a separate session for a different revision pair', function () {
    [$entry, $r1, $r2] = compareEntryWithRevisions();

    $first = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2])->content, true);
    $second = json_decode((string) postDiff(['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'current'])->content, true);

    expect($second['reused'])->toBeFalse();
    expect($second['sessionId'])->not->toBe($first['sessionId']);
});

it('400s when a version ref cannot be resolved', function () {
    [$entry] = compareEntryWithRevisions();

    $threw = false;
    try {
        postDiff(['entryId' => $entry->id, 'a' => 'rev:999999', 'b' => 'current']);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});
