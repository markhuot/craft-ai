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

        $messages = array_map(static function (MessageRecord $record): array {
            /** @var list<array<string, mixed>> $content */
            $content = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);

            return [
                'id' => $record->id,
                'role' => $record->role,
                'content' => $content,
                'dateCreated' => $record->dateCreated,
            ];
        }, $records);

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

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $message = $this->request->getRequiredBodyParam('message');

        if (! is_string($sessionId) || ! is_string($message)) {
            throw new \yii\web\BadRequestHttpException('sessionId and message must be strings.');
        }

        $message = trim($message);

        if ($message === '') {
            return $this->asJson(['queued' => false]);
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $userId = $identity !== null ? (int) $identity->id : null;

        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session !== null && $session->userId !== null && $session->userId !== $userId) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userMessage' => $message,
            'userId' => $userId,
        ]));

        return $this->asJson(['queued' => true]);
    }

    private function currentUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity !== null ? (int) $identity->id : null;
    }
}
