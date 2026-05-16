<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\db\Query;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\PageContextSerializer;
use markhuot\craftai\Plugin;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolDescriptor;
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
            'contextWindow' => $this->contextWindowSetting(),
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
        // The originating surface — defaults to whatever the request looks
        // like (CP vs front-end widget) but can be overridden by callers
        // that know they're a more specific surface, e.g. the
        // CodeComponent field's Prompt tab passes `code-component-field`
        // so its session never picks up tools that belong elsewhere.
        $rawClient = $this->request->getBodyParam('clientType');
        $clientType = is_string($rawClient) && $rawClient !== ''
            ? ClientType::tryFrom($rawClient)
            : null;
        $session->clientType = ($clientType ?? $this->resolveClientType())->value;
        $session->save();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'sessionId' => $uuid,
                'url' => UrlHelper::cpUrl("ai/session/{$uuid}"),
            ]);
        }

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
            'contextWindow' => $this->contextWindowSetting(),
        ]);
    }

    /**
     * Bootstrap the chat surface with the configured context window so its
     * progress gauge can render before the first messages poll. Falls back
     * to null when the host hasn't configured one (and Plugin's per-model
     * defaults can't resolve a value either) — the gauge hides itself in
     * that case.
     */
    private function contextWindowSetting(): ?int
    {
        try {
            $settings = Plugin::getInstance()->getSettingsArray();
        } catch (\Throwable) {
            return null;
        }

        $window = $settings['contextWindow'] ?? null;

        return is_int($window) && $window > 0 ? $window : null;
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

    /**
     * Return the current tool-mode selection for a session, plus the list of
     * tools the user has permission to use on this surface. The chat surface
     * calls this on mount to populate its permission-mode menu and to
     * re-hydrate the current selection after a page reload.
     */
    public function actionToolMode(): Response
    {
        $this->requireAcceptsJson();

        // The CP test harness delivers GET params via body, not query string.
        // Fall back to body params so production query-param requests and the
        // test harness both reach the same handler — matching the same
        // pattern AssetsController uses for its `ids` param.
        $sessionId = $this->request->getQueryParam('sessionId');
        if (! is_string($sessionId) || $sessionId === '') {
            $sessionId = $this->request->getBodyParam('sessionId');
        }
        if (! is_string($sessionId) || $sessionId === '') {
            throw new \yii\web\BadRequestHttpException('sessionId is required.');
        }

        $client = $this->resolveClientType();

        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session !== null && $session->userId !== null && $session->userId !== $this->currentUserId()) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        return $this->asJson($this->toolModePayload($session, $client));
    }

    /**
     * Persist a session's tool-mode selection. Mode must be one of:
     *  - 'full'      — all allowed tools
     *  - 'draft'     — Read + DraftWrite (no live-site mutations)
     *  - 'readonly'  — Read tools only
     *  - 'custom'    — explicit allowlist passed via `enabledTools`
     *
     * This is intentionally per-session, not per-user: each conversation can
     * pick its own surface. Changes apply on the next actionSend (the running
     * AgentLoop reads tools once at the top of its run).
     */
    public function actionUpdateToolMode(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $mode = $this->request->getRequiredBodyParam('mode');

        if (! is_string($sessionId) || $sessionId === '' || ! is_string($mode)) {
            throw new \yii\web\BadRequestHttpException('sessionId and mode must be non-empty strings.');
        }

        $allowedModes = ['full', 'draft', 'readonly', 'custom'];
        if (! in_array($mode, $allowedModes, true)) {
            throw new \yii\web\BadRequestHttpException('mode must be one of: '.implode(', ', $allowedModes));
        }

        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session === null || ($session->userId !== null && $session->userId !== $this->currentUserId())) {
            throw new \yii\web\NotFoundHttpException('Session not found.');
        }

        $enabledTools = null;
        if ($mode === 'custom') {
            $rawEnabled = $this->request->getBodyParam('enabledTools');
            $enabledTools = $this->normalizeEnabledTools($rawEnabled);
        }

        $session->toolMode = $mode;
        $session->enabledTools = $enabledTools === null ? null : json_encode($enabledTools, JSON_THROW_ON_ERROR);
        $session->save();

        return $this->asJson($this->toolModePayload($session, $this->resolveClientType()));
    }

    /**
     * Build the JSON payload that powers the front-end permission-mode menu.
     * Includes the persisted mode + the user's available tool list (post
     * permission + post client-surface filter), so the UI never offers a
     * tool the user can't actually run.
     *
     * @return array{toolMode: string, enabledTools: list<string>|null, availableTools: list<array{name: string, description: string, kind: string}>}
     */
    private function toolModePayload(?SessionRecord $session, ClientType $client): array
    {
        $registry = Plugin::getInstance()->getToolRegistry();

        // The session's stored surface wins over the request surface — a
        // CP request landing on a session that was minted for the
        // CodeComponent field still gets the field's restricted toolset.
        $sessionClient = $session !== null
            ? (ClientType::tryFrom((string) ($session->clientType ?? 'cp')) ?? $client)
            : $client;

        $descriptors = $registry->descriptors(
            includeCpOnly: $sessionClient !== ClientType::MCP,
            onlyAllowed: true,
        );
        $descriptors = $registry->filterByClient($descriptors, $sessionClient);

        $availableTools = array_map(
            static fn (ToolDescriptor $d): array => [
                'name' => $d->name,
                'description' => $d->description,
                'kind' => $d->kind->value,
            ],
            $descriptors,
        );

        $mode = $session?->toolMode ?? 'full';
        $enabled = null;
        if ($mode === 'custom' && $session?->enabledTools !== null && $session->enabledTools !== '') {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($session->enabledTools, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $enabled = array_values(array_filter($decoded, 'is_string'));
                }
            } catch (\JsonException) {
                // leave as null — corrupt JSON shouldn't break the UI.
            }
        }

        return [
            'toolMode' => $mode,
            'enabledTools' => $enabled,
            'availableTools' => $availableTools,
        ];
    }

    /**
     * Identify which surface this request is coming from. The CP chat sees
     * cpOnly tools (preview pane, fetch_webpage); the front-end widget does
     * not. Tests and console requests fall through to WIDGET (the more
     * conservative of the two), since they shouldn't be exposing CP-only
     * tools to clients that don't have the preview pane mounted.
     */
    private function resolveClientType(): ClientType
    {
        if (! $this->request instanceof \craft\web\Request) {
            return ClientType::WIDGET;
        }

        return $this->request->getIsCpRequest() ? ClientType::CP : ClientType::WIDGET;
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

        $context = $this->normalizeContext($this->request->getBodyParam('context'));

        /** @var \markhuot\craftai\agent\AgentLoop $loop */
        $loop = Craft::$container->get(\markhuot\craftai\agent\AgentLoop::class);

        // Persist the page-context note before the user's message so the
        // transcript reads as "the user navigated here, then said …" — and
        // the prompt-build path can fold the system row into the user turn
        // that follows it.
        if ($context !== null) {
            $loop->appendSystemContext($sessionId, PageContextSerializer::toSystemNote($context));
        }

        $loop->appendUserMessage($sessionId, $message, $assetIds);

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userId' => $userId,
        ]));

        return $this->asJson(['queued' => true]);
    }

    /**
     * Accept the user's checkbox selection as either a JSON-encoded array of
     * strings or a real array. Drops non-strings and tools the user can't
     * actually use (per ToolRegistry's permission check), so the persisted
     * allowlist is always meaningful — a checkbox the UI shouldn't have shown
     * never sneaks through.
     *
     * @return list<string>
     */
    private function normalizeEnabledTools(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($trimmed, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            if (! is_array($decoded)) {
                return [];
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            return [];
        }

        $registry = Plugin::getInstance()->getToolRegistry();
        $names = [];
        foreach ($value as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            // Unknown tools (typos, removed tools, attempts to allow tools
            // the user shouldn't have access to) are silently dropped here.
            // Checking describe() first avoids isAllowed() throwing on an
            // unknown name.
            if ($registry->describe($entry) === null) {
                continue;
            }
            if (! $registry->isAllowed($entry)) {
                continue;
            }
            $names[] = $entry;
        }

        return array_values(array_unique($names));
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

    /**
     * The widget POSTs the page-context payload as a JSON-encoded string in a
     * FormData field (so it survives the same body-parsing path as everything
     * else). It's only attached when the widget detects the page context has
     * changed since the last send, so most requests omit it entirely.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeContext(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (! is_array($decoded)) {
                return null;
            }
            return $decoded;
        }

        if (is_array($value)) {
            return $value;
        }

        return null;
    }
}
