<?php

namespace markhuot\craftai\listeners;

use craft\events\RegisterUrlRulesEvent;

/**
 * Register the plugin's control-panel URL rules — session views, the
 * review-comments endpoints, the per-field "AI fill" star endpoint,
 * artifact serving, the revision compare screens, and the slash-command
 * / automation edit screens.
 */
class RegisterCpUrlRules
{
    public function __invoke(RegisterUrlRulesEvent $event): void
    {
        $event->rules['ai/sessions'] = 'craft-ai/sessions/index';
        $event->rules['ai/sessions/data'] = 'craft-ai/sessions/data';
        $event->rules['POST ai/sessions/install-config'] = 'craft-ai/sessions/install-config';
        $event->rules['POST ai/sessions/new'] = 'craft-ai/sessions/new';
        $event->rules['POST ai/sessions/delete'] = 'craft-ai/sessions/delete';
        $event->rules['POST ai/sessions/stop'] = 'craft-ai/sessions/stop';
        $event->rules['POST ai/preview/respond'] = 'craft-ai/preview/respond';
        $event->rules['ai/session/<uuid:[A-Za-z0-9\-]+>'] = 'craft-ai/sessions/view';

        // Review-comments endpoints. Lookup runs on entry edit
        // page load, resolve/open-thread when the user interacts
        // with the popover. All scoped under `ai/comments/*` so a
        // host site can disable them with a single rule override.
        // `open-thread` lazily forks the comment's originating
        // session so the discussion in the chat widget stays
        // isolated from the main agent run.
        $event->rules['ai/comments'] = 'craft-ai/comments/index';
        $event->rules['POST ai/comments/resolve'] = 'craft-ai/comments/resolve';
        $event->rules['POST ai/comments/open-thread'] = 'craft-ai/comments/open-thread';
        // User-initiated span comments from the CKEditor toolbar
        // plugin land here. The endpoint mints a fresh session
        // (so the comment owns its own discussion thread the
        // same way agent-created ones do) and returns the new
        // comment payload to the editor JS for span wrapping.
        $event->rules['POST ai/comments/create'] = 'craft-ai/comments/create';

        // Per-field "AI fill" star. The CP overlay decorates
        // every field on an entry edit screen with a star
        // button — clicking it POSTs here to spin up a fresh
        // session pre-seeded with element + field context, and
        // the widget opens against the returned session id so
        // the editor watches the agent fill the field live.
        $event->rules['POST ai/ai-star/fill-field'] = 'craft-ai/ai-star/fill-field';

        // Serves an agent-authored HTML artifact (e.g. a rendered
        // revision diff) as a standalone, sandboxed document. Auth +
        // ownership are enforced in the controller.
        $event->rules['ai/artifacts/<id:\d+>'] = 'craft-ai/artifacts/view';

        // Revision compare view. `index` renders the full-page picker
        // UI; `diff` is the synchronous recompute endpoint the pickers
        // call; `revisions` lists revisions for the pickers as JSON.
        $event->rules['ai/compare'] = 'craft-ai/compare/index';
        $event->rules['ai/compare/revisions'] = 'craft-ai/compare/revisions';
        $event->rules['POST ai/compare/diff'] = 'craft-ai/compare/diff';

        // Dedicated edit screen for a single slash command. The
        // plugin settings page links here from each row in its
        // (read-only) commands list, because a slash-command
        // prompt can grow longer than a settings-table cell
        // comfortably renders. UID is constrained to a UUID
        // shape so the route doesn't shadow `new`.
        $event->rules['ai/commands/new'] = 'craft-ai/commands/edit';
        // Pattern is broader than just a UUID so it also matches the
        // hardcoded UIDs on seeded defaults (see Command::defaults).
        // `new` is registered above so it short-circuits this rule.
        $event->rules['ai/commands/<uid:[A-Za-z0-9\-]+>'] = 'craft-ai/commands/edit';
        $event->rules['POST ai/commands/save'] = 'craft-ai/commands/save';
        $event->rules['POST ai/commands/delete'] = 'craft-ai/commands/delete';

        // Automation rules: dedicated edit screen mirrors the
        // slash-command flow above. Same `new`-first ordering so
        // the literal route short-circuits the parameterized one.
        $event->rules['ai/automations/new'] = 'craft-ai/automations/edit';
        $event->rules['ai/automations/<uid:[A-Za-z0-9\-]+>'] = 'craft-ai/automations/edit';
        $event->rules['POST ai/automations/save'] = 'craft-ai/automations/save';
        $event->rules['POST ai/automations/delete'] = 'craft-ai/automations/delete';
    }
}
