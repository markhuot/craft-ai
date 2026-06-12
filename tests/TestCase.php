<?php

namespace Tests;

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\migrations\Install;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase as PestTestCase;
use yii\base\Event;

class TestCase extends PestTestCase
{
    use RefreshesDatabase;

    protected function setUp(): void
    {
        $this->disableDebugToolbar();

        static $migrated = false;
        if (! $migrated) {
            $migrated = true;
            $db = Craft::$app->getDb();
            foreach ([
                // Drop FK-child tables before the tables they reference —
                // craftai_comparisons has foreign keys onto craftai_artifacts
                // and craftai_sessions, and craftai_artifacts has one onto
                // craftai_sessions, so they have to go first.
                '{{%craftai_comparisons}}',
                '{{%craftai_artifacts}}',
                '{{%craftai_messages}}',
                '{{%craftai_sessions}}',
                '{{%craftai_preview_requests}}',
                '{{%craftai_comments}}',
                '{{%craftai_scheduled_runs}}',
            ] as $table) {
                if ($db->getSchema()->getTableSchema($table, true) !== null) {
                    $db->createCommand()->dropTable($table)->execute();
                }
            }

            $plugins = Craft::$app->getPlugins();
            if ($plugins->getPlugin('craft-ai') === null) {
                $plugins->installPlugin('craft-ai');
            } elseif ($db->getSchema()->getTableSchema('{{%craftai_messages}}', true) === null) {
                // Plugin is registered as installed but its tables were
                // dropped above without a matching uninstall — happens when
                // a previous test process installed, the install record
                // persisted across runs, and this process's drops left the
                // schema empty. Re-run the install migration manually so
                // each fresh test process gets a populated schema.
                $migration = new Install();
                $migration->db = $db;
                $migration->safeUp();
            }
        }

        // Re-align the cached configVersion with the database before craft-pest
        // opens this test's transaction (it captures the version inside
        // parent::setUp()). A project-config write that triggers an
        // auto-committed DDL bumps the info table's configVersion in a way that
        // outlives the transaction rollback, while craft-pest only restores the
        // *in-memory* Info. A fresh-install database (every CI run) hits this;
        // a long-lived dev database usually doesn't, which is why it reproduced
        // only in CI. Left unhandled, the next project-config write reads a DB
        // version that no longer matches the cached one and
        // ProjectConfig::_acquireLock() throws StaleResourceException — which
        // then cascades across every project-config-touching test.
        $storedConfigVersion = (new \craft\db\Query())
            ->select(['configVersion'])
            ->from(\craft\db\Table::INFO)
            ->scalar();
        if (is_string($storedConfigVersion) && $storedConfigVersion !== '') {
            Craft::$app->getInfo()->configVersion = $storedConfigVersion;
        }

        parent::setUp();

        // craft-pest's Application::bootstrap() injects an `X-Debug: enable`
        // header whenever devMode is on, which makes Craft bootstrap the
        // yii2-debug module. Its LogTarget then buffers every log message and
        // DB query for the life of the PHP process — there's no per-request
        // flush in the test harness — and the debug panels try to serialize
        // the whole accumulated pile, which blows past the memory limit partway
        // through a full-suite run (a ~600MB single allocation in
        // yii\debug\LogTarget::export()). Detaching the target keeps the leak
        // from ever starting; it's idempotent, so running it each setUp is fine.
        $log = Craft::$app->getLog();
        if (isset($log->targets['debug'])) {
            unset($log->targets['debug']);
        }

        // Tool execution now goes through Craft permission checks. Default to
        // an admin identity so existing tests pass; tests that need to verify
        // permission denial can override the identity within the test body.
        $admin = new User();
        $admin->id = 1;
        $admin->admin = true;
        Craft::$app->getUser()->setIdentity($admin);

        // Default the shared ToolContext to the CP surface so tests model the
        // primary user-facing path (the in-app chat). Tests exercising the MCP
        // or widget surfaces can re-call begin() with a different ClientType.
        /** @var ToolContext $context */
        $context = Craft::$container->get(ToolContext::class);
        $context->begin('test-session', null, ClientType::CP);
    }

    /**
     * Tear down Craft's debug toolbar so it can't accumulate state across the
     * whole test process.
     *
     * craft-pest's web Application adds an `X-Debug: enable` header whenever
     * devMode is on, which makes Craft bootstrap the full debug toolbar
     * (craft\debug\Module) once, at app construction. In a real request the
     * toolbar's per-request data is exported and reset on EVENT_AFTER_REQUEST,
     * but the test process reuses a single long-lived Craft::$app and never
     * completes that cycle. So the module's panels keep collecting forever:
     * the EventPanel attaches a wildcard Event::on('*', '*') listener that
     * records *every* event fired in *every* test, and the log/profiling
     * panels record every message and DB query. Left alone this grows the heap
     * to ~1GB by mid-suite and, because each fired event now runs the wildcard
     * collector, makes every later test 10-60x slower than it is in isolation.
     *
     * We can't stop craft-pest sending the header, so instead we dismantle the
     * toolbar once: drop its log target, remove the wildcard event listener,
     * and clear whatever the panels have already collected. devMode itself
     * stays on, so error reporting, deprecation handling, and Twig behavior are
     * unchanged.
     */
    protected function disableDebugToolbar(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $debug = Craft::$app->getModule('debug', false);
        if ($debug === null) {
            return;
        }

        // Stop the logger feeding the debug log target.
        $dispatcher = Craft::getLogger()->dispatcher;
        $targets = $dispatcher->targets;
        unset($targets['debug']);
        $dispatcher->targets = $targets;

        // Remove the EventPanel's wildcard listener (Event::on('*', '*', ...))
        // that records every event fired anywhere in the app, and clear any
        // data the panels have already buffered.
        Event::off('*', '*');

        $ref = new \ReflectionObject($debug);
        if ($ref->hasProperty('panels')) {
            $panels = $ref->getProperty('panels');
            $panels->setAccessible(true);
            foreach ((array) $panels->getValue($debug) as $panel) {
                if (! is_object($panel)) {
                    continue;
                }
                $pref = new \ReflectionObject($panel);
                if ($pref->hasProperty('_events')) {
                    $events = $pref->getProperty('_events');
                    $events->setAccessible(true);
                    $events->setValue($panel, []);
                }
            }
        }
    }
}
