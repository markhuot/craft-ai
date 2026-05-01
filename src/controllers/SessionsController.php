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
        return $this->renderTemplate('craft-ai/sessions/view', [
            'sessionId' => '',
            'sessionTitle' => null,
            'messages' => [],
            'initialSessions' => $this->collectSessionListPayload(),
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
        /** @var array<string, array{messageCount: int, firstMessage: ?string, lastMessage: ?string}> $stats */
        $stats = (new Query())
            ->select([
                'sessionId',
                'messageCount' => 'COUNT(*)',
                'firstMessage' => 'MIN([[dateCreated]])',
                'lastMessage' => 'MAX([[dateCreated]])',
            ])
            ->from('{{%craftai_messages}}')
            ->groupBy('sessionId')
            ->indexBy('sessionId')
            ->all();

        /** @var list<SessionRecord> $sessions */
        $sessions = SessionRecord::find()->all();

        $sessionsById = [];
        foreach ($sessions as $session) {
            $sessionsById[$session->id] = $session;
        }

        $ids = array_unique([...array_keys($sessionsById), ...array_keys($stats)]);

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
        $session->save();

        return $this->redirect(UrlHelper::cpUrl("ai/session/{$uuid}"));
    }

    public function actionView(string $uuid): Response
    {
        $session = SessionRecord::findOne(['id' => $uuid]);

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

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userMessage' => $message,
        ]));

        return $this->asJson(['queued' => true]);
    }
}
