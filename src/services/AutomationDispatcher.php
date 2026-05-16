<?php

namespace markhuot\craftai\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\models\Automation;
use markhuot\craftai\Plugin;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\SessionRecord;

/**
 * Translates a Craft event into zero-or-more agent sessions.
 *
 * Called from the {@see Plugin}-registered event listeners for each
 * supported event type. Looks at the current plugin settings to find
 * matching automation rules, creates a fresh session per match,
 * pre-seeds it with the user's prompt + a system note describing the
 * triggering element, then pushes an {@see AgentJob} so the agent loop
 * runs asynchronously on the queue worker.
 *
 * **Recursion guard**: when the dispatcher is invoked from inside a tool
 * call (the agent saved an entry / applied a draft / etc.), the shared
 * {@see ToolContext} has a session id set. The dispatcher skips in that
 * case so an automation can't trigger itself or cascade across agents.
 */
class AutomationDispatcher
{
    public function __construct(
        private readonly ToolContext $toolContext = new ToolContext(),
    ) {}

    public function dispatch(string $eventKey, ElementInterface $element): void
    {
        // Skip when we're inside an agent loop — any save the agent did
        // would otherwise re-trigger automations and spawn new sessions.
        if ($this->toolContext->getSessionId() !== null) {
            return;
        }

        // Console runs are typically scripted bulk operations (migrations,
        // imports, etc.) where firing automations per element would be
        // noisy and surprising. Live CP / front-end requests still fire.
        if (Craft::$app->getRequest() instanceof \craft\console\Request) {
            return;
        }

        foreach ($this->loadAutomations() as $auto) {
            if (! $auto->enabled) continue;
            if ($auto->event !== $eventKey) continue;
            if (! $this->matchesElementFilter($auto, $element)) continue;

            $this->fire($auto, $element);
        }
    }

    /**
     * @return list<Automation>
     */
    private function loadAutomations(): array
    {
        try {
            $plugin = Plugin::getInstance();
        } catch (\Throwable) {
            return [];
        }

        $settings = $plugin->getSettings();
        if (! $settings instanceof \markhuot\craftai\models\Settings) {
            return [];
        }

        return $settings->getAutomations();
    }

    /**
     * Section filter applies to entries only. Asset/entry-delete rules
     * leave it empty so they fire site-wide. Empty handle on an entry
     * rule also fires for every section — the filter is purely additive.
     */
    private function matchesElementFilter(Automation $auto, ElementInterface $element): bool
    {
        if ($auto->sectionHandle === '') {
            return true;
        }

        if (! $element instanceof Entry) {
            return false;
        }

        try {
            $section = $element->getSection();
        } catch (\Throwable) {
            return false;
        }

        return $section !== null && $section->handle === $auto->sectionHandle;
    }

    private function fire(Automation $auto, ElementInterface $element): void
    {
        $sessionId = StringHelper::UUID();

        $session = new SessionRecord();
        $session->id = $sessionId;
        $session->active = false;
        $session->userId = $this->resolveUserId();
        $session->title = $this->buildTitle($auto, $element);
        $session->save();

        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);
        $loop->appendUserMessage($sessionId, $auto->prompt);
        $loop->appendSystemContext($sessionId, $this->buildElementContext($auto, $element));

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userId' => $session->userId,
        ]));
    }

    private function buildTitle(Automation $auto, ElementInterface $element): string
    {
        $label = $auto->name !== '' ? $auto->name : $auto->event;
        $target = $this->shortElementLabel($element);
        $title = "{$label}: {$target}";
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }
        return $title;
    }

    private function shortElementLabel(ElementInterface $element): string
    {
        // getUiLabel() is declared on ElementInterface in Craft 5, so we
        // can call it unconditionally. Fall back to $title for elements
        // whose UI label resolves empty (e.g. unsaved drafts).
        $title = (string) $element->getUiLabel();
        if ($title === '' && isset($element->title)) {
            $title = (string) $element->title;
        }
        if ($title !== '') {
            return $title;
        }
        $kind = $element::refHandle() ?: 'element';
        return "{$kind} #{$element->id}";
    }

    private function buildElementContext(Automation $auto, ElementInterface $element): string
    {
        $label = $auto->name !== '' ? $auto->name : '(unnamed)';
        $title = $this->shortElementLabel($element);

        if ($element instanceof Entry) {
            $isDraft = $element->getIsDraft();
            $idArg = $isDraft
                ? "draftId: {$element->draftId} (canonical entry #{$element->canonicalId})"
                : "entryId: {$element->id}";
            $fetchTool = $isDraft ? '`get_draft`' : '`get_entry`';

            $sectionLine = '';
            try {
                $section = $element->getSection();
                if ($section !== null) {
                    $sectionLine = "\nSection: {$section->handle}";
                }
            } catch (\Throwable) {
                // Nested-Matrix entries lack a section — skip the line.
            }

            return <<<NOTE
                [Automation "{$label}" triggered by `{$auto->event}`]
                Target: {$title} ({$idArg}){$sectionLine}
                Use {$fetchTool} to read the element. Apply the user prompt
                above to it. If you find issues that should surface inline
                in the CP, use `leave_comment` with the matching field
                handle so they appear next to the relevant fields.
                NOTE;
        }

        if ($element instanceof Asset) {
            return <<<NOTE
                [Automation "{$label}" triggered by `{$auto->event}`]
                Target asset #{$element->id} ("{$title}").
                Use `get_asset` to read it; apply the user prompt above.
                NOTE;
        }

        $kind = $element::refHandle() ?: 'element';
        return "[Automation \"{$label}\" triggered by `{$auto->event}`]\nTarget {$kind} #{$element->id} (\"{$title}\").";
    }

    private function resolveUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();
        return $identity !== null && isset($identity->id) ? (int) $identity->id : null;
    }
}
