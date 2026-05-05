<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\db\Query;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use yii\web\Response;

class SessionsController extends Controller
{
    public function actionIndex(): Response
    {
        if (($setup = $this->renderSetupIfNeeded()) !== null) {
            return $setup;
        }

        return $this->renderTemplate('craft-ai/sessions/view', [
            'sessionId' => '',
            'sessionTitle' => null,
            'messages' => [],
            'initialSessions' => $this->collectSessionListPayload(),
        ]);
    }

    public function actionInstallConfig(): Response
    {
        $this->requirePostRequest();

        $destination = $this->configFilePath();

        if (is_file($destination)) {
            $this->setSuccessFlash(Craft::t('craft-ai', 'Config file already exists.'));

            return $this->redirect(UrlHelper::cpUrl('ai/sessions'));
        }

        $source = dirname(__DIR__) . '/config.php';

        if (! is_file($source)) {
            throw new \RuntimeException("craft-ai: example config not found at {$source}.");
        }

        $configDir = dirname($destination);
        if (! is_dir($configDir) && ! @mkdir($configDir, 0775, true) && ! is_dir($configDir)) {
            throw new \RuntimeException("craft-ai: unable to create config directory {$configDir}.");
        }

        if (! @copy($source, $destination)) {
            throw new \RuntimeException("craft-ai: unable to copy example config to {$destination}.");
        }

        $this->setSuccessFlash(Craft::t(
            'craft-ai',
            'Copied example config to {path}. Set "provider" (and "apiKey") then reload this page.',
            ['path' => 'config/craft-ai.php'],
        ));

        return $this->redirect(UrlHelper::cpUrl('ai/sessions'));
    }

    private function configFilePath(): string
    {
        return Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'craft-ai.php';
    }

    private function renderSetupIfNeeded(): ?Response
    {
        $configPath = $this->configFilePath();
        $configExists = is_file($configPath);
        $provider = null;

        if ($configExists) {
            /** @var array{provider?: ?string} $config */
            $config = Craft::$app->getConfig()->getConfigFromFile('craft-ai');
            $provider = $config['provider'] ?? null;
        }

        if ($configExists && is_string($provider) && $provider !== '') {
            return null;
        }

        return $this->renderTemplate('craft-ai/sessions/setup', [
            'configExists' => $configExists,
            'configPath' => $configPath,
            'installUrl' => UrlHelper::actionUrl('craft-ai/sessions/install-config'),
        ]);
    }

    public function actionData(): Response
    {
        $this->requireAcceptsJson();

        return $this->asJson(['sessions' => $this->collectSessionListPayload()]);
    }

    /**
     * @return list<array{sessionId: string, url: string, title: ?string, active: bool, messageCount: int, firstMessage: string, lastMessage: string}>
     */
    private function collectSessionListPayload(): array
    {
        $rows = $this->collectSessionRows();
        $formatter = Craft::$app->getFormatter();

        return array_map(static function (array $row) use ($formatter): array {
            return [
                'sessionId' => $row['sessionId'],
                'url' => UrlHelper::cpUrl('ai/session/'.$row['sessionId']),
                'title' => $row['title'],
                'active' => $row['active'],
                'messageCount' => $row['messageCount'],
                'firstMessage' => $row['firstMessage'] !== null
                    ? $formatter->asDate($row['firstMessage'], 'short').' '.$formatter->asTime($row['firstMessage'], 'short')
                    : '',
                'lastMessage' => $row['lastMessage'] !== null
                    ? $formatter->asDate($row['lastMessage'], 'short').' '.$formatter->asTime($row['lastMessage'], 'short')
                    : '',
            ];
        }, $rows);
    }

    /**
     * @return list<array{sessionId: string, title: ?string, active: bool, messageCount: int, firstMessage: ?string, lastMessage: ?string}>
     */
    private function collectSessionRows(): array
    {
        $userId = $this->currentUserId();

        /** @var list<SessionRecord> $sessions */
        $sessions = SessionRecord::find()
            ->where(['userId' => $userId])
            ->all();

        $sessionsById = [];
        foreach ($sessions as $session) {
            $sessionsById[$session->id] = $session;
        }

        $ids = array_keys($sessionsById);

        /** @var array<string, array{messageCount: int, firstMessage: ?string, lastMessage: ?string}> $stats */
        $stats = $ids === [] ? [] : (new Query())
            ->select([
                'sessionId',
                'messageCount' => 'COUNT(*)',
                'firstMessage' => 'MIN([[dateCreated]])',
                'lastMessage' => 'MAX([[dateCreated]])',
            ])
            ->from('{{%craftai_messages}}')
            ->where(['sessionId' => $ids])
            ->groupBy('sessionId')
            ->indexBy('sessionId')
            ->all();

        $rows = [];
        foreach ($ids as $id) {
            $session = $sessionsById[$id] ?? null;
            $stat = $stats[$id] ?? null;
            $rows[] = [
                'sessionId' => $id,
                'title' => $session?->title,
                'active' => $session !== null && (bool) $session->active,
                'messageCount' => $stat['messageCount'] ?? 0,
                'firstMessage' => $stat['firstMessage'] ?? ($session->dateCreated ?? null),
                'lastMessage' => $stat['lastMessage'] ?? ($session->dateUpdated ?? null),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['lastMessage'], (string) $a['lastMessage']));

        return $rows;
    }

    public function actionNew(): Response
    {
        $this->requirePostRequest();

        $uuid = StringHelper::UUID();

        $session = new SessionRecord();
        $session->id = $uuid;
        $session->active = false;
        $session->userId = $this->currentUserId();
        $session->save();

        return $this->redirect(UrlHelper::cpUrl("ai/session/{$uuid}"));
    }

    public function actionView(string $uuid): Response
    {
        if (($setup = $this->renderSetupIfNeeded()) !== null) {
            return $setup;
        }

        $session = SessionRecord::findOne(['id' => $uuid]);

        if ($session !== null && $session->userId !== null && $session->userId !== $this->currentUserId()) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        /** @var list<MessageRecord> $records */
        $records = MessageRecord::find()
            ->where(['sessionId' => $uuid])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $messages = array_map(
            static fn (MessageRecord $record): array => MessagesController::serializeMessage($record),
            $records,
        );

        return $this->renderTemplate('craft-ai/sessions/view', [
            'sessionId' => $uuid,
            'sessionTitle' => $session?->title,
            'messages' => $messages,
            'initialSessions' => $this->collectSessionListPayload(),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');

        if (! is_string($sessionId) || $sessionId === '') {
            throw new \yii\web\BadRequestHttpException('sessionId must be a non-empty string.');
        }

        $session = SessionRecord::findOne(['id' => $sessionId]);

        if ($session === null || ($session->userId !== null && $session->userId !== $this->currentUserId())) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        MessageRecord::deleteAll(['sessionId' => $sessionId]);
        SessionRecord::deleteAll(['id' => $sessionId]);

        $this->setSuccessFlash(Craft::t('craft-ai', 'Session deleted.'));

        return $this->redirect(UrlHelper::cpUrl('ai/sessions'));
    }

    public function actionStop(): Response
    {
        $this->requirePostRequest();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');

        if (! is_string($sessionId) || $sessionId === '') {
            throw new \yii\web\BadRequestHttpException('sessionId must be a non-empty string.');
        }

        $session = SessionRecord::findOne(['id' => $sessionId]);

        if ($session === null || ($session->userId !== null && $session->userId !== $this->currentUserId())) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        // Idempotent: setting the flag is safe whether or not a job is running.
        // The agent loop polls it between turns and breaks at the next safe
        // point; AgentJob clears it again when starting a fresh run.
        $session->stopRequested = true;
        $session->save();

        $this->setSuccessFlash(Craft::t('craft-ai', 'Stop requested. The agent will halt after its current step.'));

        return $this->redirect(UrlHelper::cpUrl("ai/session/{$sessionId}"));
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $message = $this->request->getRequiredBodyParam('message');

        if (! is_string($sessionId) || ! is_string($message)) {
            throw new \yii\web\BadRequestHttpException('sessionId and message must be strings.');
        }

        $assetIds = $this->normalizeAssetIds($this->request->getBodyParam('assetIds'));
        $message = trim($message);

        if ($message === '' && $assetIds === []) {
            return $this->asJson(['queued' => false]);
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $userId = $identity !== null ? (int) $identity->id : null;

        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session !== null && $session->userId !== null && $session->userId !== $userId) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        /** @var \markhuot\craftai\agent\AgentLoop $loop */
        $loop = Craft::$container->get(\markhuot\craftai\agent\AgentLoop::class);
        $loop->appendUserMessage($sessionId, $message, $assetIds);

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userId' => $userId,
        ]));

        return $this->asJson(['queued' => true]);
    }

    /**
     * Tolerate JSON-encoded strings, comma-separated strings, single ints, or
     * arrays — POST bodies can deliver any of these depending on whether the
     * client used `FormData.append` per id, a JSON.stringify, or just a single
     * value. Drops anything that isn't a positive integer.
     *
     * @return list<int>
     */
    private function normalizeAssetIds(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return [];
                }
                if (! is_array($decoded)) {
                    return [];
                }
                $value = $decoded;
            } else {
                $value = explode(',', $trimmed);
            }
        }

        if (is_int($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[] = $entry;
                continue;
            }
            if (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed !== '' && ctype_digit($trimmed)) {
                    $intVal = (int) $trimmed;
                    if ($intVal > 0) {
                        $ids[] = $intVal;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function currentUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity !== null ? (int) $identity->id : null;
    }
}
