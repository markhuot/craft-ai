<?php

namespace markhuot\craftai\preview;

use markhuot\craftai\records\PreviewRequestRecord;

/**
 * Brokers blocking, out-of-band requests between agent-loop tools and the CP
 * front-end. The agent loop runs inside a queue worker and tools complete
 * synchronously, so when a tool needs to drive the UI (open an iframe, read
 * its contents, etc.) it writes a request row here, polls it for resolution,
 * and the CP front-end POSTs back through the PreviewController to flip the
 * row's status. No pub/sub or websocket required — just a database flag the
 * tool watches at ~250ms cadence.
 */
class PreviewService
{
    /**
     * Poll cadence used by {@see waitFor()}. Small enough that perceived
     * latency between the iframe firing onload and the agent unblocking
     * stays under a second; large enough that we don't hammer the DB.
     */
    private const POLL_INTERVAL_MICROS = 250_000;

    /**
     * Lower bound on tool-supplied timeouts. Prevents an LLM from accidentally
     * setting a sub-second deadline that's guaranteed to fail.
     */
    public const MIN_TIMEOUT_SECONDS = 5;

    /**
     * Hard cap so a misbehaving tool can never tie up a queue worker for
     * longer than this. The default Craft queue job timeout is 5 minutes,
     * so this leaves comfortable headroom.
     */
    public const MAX_TIMEOUT_SECONDS = 120;

    /**
     * Persist a new pending request and return its DB id so the caller can
     * poll for resolution.
     *
     * @param array<string, mixed> $input
     */
    public function create(string $sessionId, ?string $toolUseId, string $type, array $input): int
    {
        $record = new PreviewRequestRecord();
        $record->sessionId = $sessionId;
        $record->toolUseId = $toolUseId;
        $record->type = $type;
        $record->input = json_encode($input, JSON_THROW_ON_ERROR);
        $record->status = PreviewRequestRecord::STATUS_PENDING;
        $record->save(false);

        /** @var int $id */
        $id = $record->id;

        return $id;
    }

    /**
     * Block until the given request reaches a terminal status, or the timeout
     * elapses. Returns the resolved record. On timeout, flips the row to
     * `errored` with a synthesized timeout payload so the front-end won't
     * pick it up after the tool has given up.
     *
     * @param int $timeoutSeconds Clamped to [MIN_TIMEOUT_SECONDS, MAX_TIMEOUT_SECONDS].
     * @param ?callable(): bool $shouldAbort Optional cooperative-cancel hook —
     *        return true and the wait short-circuits with an `errored` row.
     *        AgentLoop uses this to bail when the user clicks Stop.
     */
    public function waitFor(int $id, int $timeoutSeconds, ?callable $shouldAbort = null): PreviewRequestRecord
    {
        $timeout = max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeoutSeconds));
        $deadline = microtime(true) + $timeout;

        while (true) {
            $record = $this->find($id);
            if ($record === null) {
                throw new \RuntimeException("Preview request {$id} disappeared mid-wait.");
            }

            if (
                $record->status === PreviewRequestRecord::STATUS_COMPLETED
                || $record->status === PreviewRequestRecord::STATUS_ERRORED
            ) {
                return $record;
            }

            if ($shouldAbort !== null && $shouldAbort()) {
                return $this->fail($id, 'Stopped by user.');
            }

            if (microtime(true) >= $deadline) {
                return $this->fail($id, "Timed out after {$timeout}s waiting for the front-end to respond.");
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    public function find(int $id): ?PreviewRequestRecord
    {
        /** @var ?PreviewRequestRecord $record */
        $record = PreviewRequestRecord::findOne(['id' => $id]);

        return $record;
    }

    /**
     * Return the oldest pending request for the session — what the front-end's
     * poll handler should pick up next. Returns null when nothing is pending.
     * The front-end's local dedup keeps a single tab from re-handling the
     * same row across consecutive polls; cross-tab races resolve harmlessly
     * because `complete()` / `fail()` are idempotent on already-resolved rows.
     */
    public function nextActionable(string $sessionId): ?PreviewRequestRecord
    {
        /** @var ?PreviewRequestRecord $record */
        $record = PreviewRequestRecord::find()
            ->where([
                'sessionId' => $sessionId,
                'status' => PreviewRequestRecord::STATUS_PENDING,
            ])
            ->orderBy(['id' => SORT_ASC])
            ->one();

        return $record;
    }

    /**
     * Resolve the request with the result the front-end produced. `result`
     * is whatever shape the corresponding tool expects — for OpenPreview
     * that's `{loadedAt: int, finalUrl?: string}`, for GetPreview it's
     * `{content: string, mode: 'text'|'full'}`.
     *
     * @param array<string, mixed> $result
     */
    public function complete(int $id, array $result): ?PreviewRequestRecord
    {
        $record = $this->find($id);
        if ($record === null) {
            return null;
        }
        if (
            $record->status === PreviewRequestRecord::STATUS_COMPLETED
            || $record->status === PreviewRequestRecord::STATUS_ERRORED
        ) {
            return $record;
        }
        $record->status = PreviewRequestRecord::STATUS_COMPLETED;
        $record->result = json_encode($result, JSON_THROW_ON_ERROR);
        $record->save(false);

        return $record;
    }

    public function fail(int $id, string $message): PreviewRequestRecord
    {
        $record = $this->find($id);
        if ($record === null) {
            throw new \RuntimeException("Preview request {$id} not found.");
        }
        if ($record->status === PreviewRequestRecord::STATUS_ERRORED) {
            return $record;
        }
        $record->status = PreviewRequestRecord::STATUS_ERRORED;
        $record->result = json_encode(['error' => $message], JSON_THROW_ON_ERROR);
        $record->save(false);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeInput(PreviewRequestRecord $record): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($record->input, true, 16, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeResult(PreviewRequestRecord $record): array
    {
        if ($record->result === null || $record->result === '') {
            return [];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($record->result, true, 16, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException) {
            return [];
        }
    }
}
