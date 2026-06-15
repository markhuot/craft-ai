<?php

namespace Tests\Support;

use CraftCms\Aliases\AliasesServiceProvider;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Providers\AppServiceProvider;
use CraftCms\Cms\Providers\CraftServiceProvider;
use CraftCms\DependencyAwareCache\CacheServiceProvider;
use CraftCms\Yii2Adapter\Yii2ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Tinker\TinkerServiceProvider;
use Laravel\Wayfinder\WayfinderServiceProvider;
use ReflectionClass;
use ReflectionObject;
use Yiisoft\Aliases\Aliases;

/**
 * Shared Testbench wiring for running the craft-ai plugin under Craft 6.
 *
 * Used by both the per-test base ({@see \Tests\TestCase}) and the one-time
 * database builder ({@see \Tests\Support\SetupCase}) so the two boot Craft
 * identically — same provider stack, aliases, auth guard, and factory
 * resolution.
 */
trait CraftSixHarness
{
    /**
     * The plugin runs through craftcms/yii2-adapter, so the test app needs the
     * same provider stack Craft's own suite boots — most importantly the
     * adapter's Yii2ServiceProvider, which loads the legacy `\Craft` class and
     * binds the `Craft` service the adapter Plugin base resolves in its static
     * create(). Testbench doesn't run package auto-discovery, so they're listed
     * explicitly. Aliases + dependency-aware cache come before Craft; Wayfinder
     * after, mirroring Craft's testbench.yaml.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            TinkerServiceProvider::class,
            AliasesServiceProvider::class,
            CacheServiceProvider::class,
            CraftServiceProvider::class,
            WayfinderServiceProvider::class,
            // Last: its boot() eagerly resolves the `Craft` service, which boots
            // the legacy Yii app and reads aliases Craft core sets up above.
            Yii2ServiceProvider::class,
        ];
    }

    /**
     * Register Craft's facade class aliases (Assets, DeltaRegistry, Sites, …).
     *
     * These ship in the cms package's `extra.laravel.aliases`, normally applied
     * by Laravel package auto-discovery — which Testbench doesn't run (hence the
     * explicit provider list above). The CP Twig layer needs them: Craft's
     * LaravelExtension turns every registered AliasLoader facade into a Twig
     * global, so `_layouts/cp.twig`'s `DeltaRegistry.getModifiedNames()` (and
     * peers) resolve to the facade instead of null. Loaded straight from the
     * cms composer.json so the set stays complete as Craft adds facades.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        $cmsRoot = dirname((new ReflectionClass(AppServiceProvider::class))->getFileName(), 3);
        $composer = json_decode((string) file_get_contents("$cmsRoot/composer.json"), true);

        return $composer['extra']['laravel']['aliases'] ?? [];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $cmsRoot = dirname((new ReflectionClass(AppServiceProvider::class))->getFileName(), 3);
        $craftDir = dirname(__DIR__).'/.craft';

        // Runtime dirs the legacy bootstrap points its @templates/@storage
        // aliases at. Created here so fresh checkouts / CI don't need committed
        // empty directories.
        @mkdir("$craftDir/templates", 0777, true);
        @mkdir("$craftDir/storage", 0777, true);

        // Craft registers the `craft` guard/provider but doesn't make it the
        // default (a real install's config/auth.php does). Without this the
        // default `web` guard returns a generic Laravel user and Craft's
        // permission Gate hooks reject it.
        $app['config']->set('auth.defaults.guard', 'craft');

        // Identify the legacy Craft app as the test app (the id Craft's own
        // test harness uses). This switches Craft into test mode — notably it
        // swaps the DB-backed mutex for a NullMutex, so the schedule dispatcher
        // and anything else mutex-guarded doesn't carry locks between tests.
        $app['config']->set('craft.app.id', 'craft-test');

        // The legacy mutex component registers a process-lifetime
        // register_shutdown_function() the first time it's resolved. Under the
        // Testbench harness that closure (it captures the Mutex component and
        // its lock list by reference) makes PHP exit 255 at shutdown the moment
        // any lock has been acquired+released during the run — even though the
        // inner driver is a no-op NullMutex. Disabling autoRelease stops the
        // closure from registering, so the schedule dispatcher (and anything
        // else that takes a lock) tears down cleanly.
        $app['config']->set('craft.app.components.mutex', [
            'class' => \craft\mutex\Mutex::class,
            'mutex' => \craft\mutex\NullMutex::class,
            'autoRelease' => false,
        ]);

        // Pin @craftcms (and the runtime dirs) on the shared Aliases instance
        // the moment it's built, so Craft's IconServiceProvider can resolve
        // @craftcms/resources/icons/aliases.php and the legacy bootstrap finds a
        // defined @templates/@storage regardless of provider boot order.
        $app->resolving(Aliases::class, function (Aliases $aliases) use ($cmsRoot, $craftDir): void {
            $aliases->set('@craftcms', $cmsRoot);
            $aliases->set('@resources', "$cmsRoot/resources");
            $aliases->set('@templates', "$craftDir/templates");
            $aliases->set('@storage', "$craftDir/storage");
        });

        // Craft's model factories live in CraftCms\Cms\Database\Factories\*Factory,
        // not Laravel's default Database\Factories namespace. Point the resolver
        // there so Model::factory() works in plugin tests.
        Factory::guessFactoryNamesUsing(
            static fn (string $model): string => 'CraftCms\\Cms\\Database\\Factories\\'.class_basename($model).'Factory',
        );
    }

    /**
     * Load + boot the plugin for tests.
     *
     * The native InstallsPlugin::bootPluginProvider() force-instantiates the
     * plugin and calls ->boot($plugins) — but the bundled plugin runs through
     * craftcms/yii2-adapter, so its Plugin class is the Yii-style
     * craft\base\Plugin which registers everything in init() (run during
     * construction) and has no boot() method. loadPlugins() alone won't load it
     * either: under the harness the plugin isn't enabled in project config, so
     * loadPlugins skips it.
     *
     * So instantiate the adapter plugin directly via createPlugin(), which runs
     * its constructor (init() → events + container bindings, and setInstance()
     * so Plugin::getInstance() resolves). Do it once per process — re-running
     * init() per test would stack duplicate event listeners.
     */
    public function bootPluginProvider(): void
    {
        $this->registerWebRequestTestSupport();

        $this->app->get(Plugins::class)->loadPlugins();

        // Instantiate the adapter plugin directly when it isn't already the
        // registered instance. createPlugin()/loadPlugins() need a project-config
        // `plugins.craft-ai` entry that the committed builder doesn't persist;
        // constructing the Yii module runs its init() (events + container
        // bindings) and setInstance() so Plugin::getInstance() resolves. The
        // adapter's getInstance() return type is non-null, so probe it through
        // the framework method (null-safe) and only build when missing — Craft
        // resets the module instance between tests, but we avoid re-running
        // init() (and stacking duplicate event listeners) when it survives.
        $class = \markhuot\craftai\Plugin::class;

        try {
            // Throws (non-null return type) when no instance is registered.
            if ($class::getInstance() instanceof $class) {
                return;
            }
        } catch (\TypeError) {
            // Not registered yet — fall through and build it.
        }

        $basePath = dirname((new ReflectionClass($class))->getFileName());

        new $class('craft-ai', \Craft::$app, ['basePath' => $basePath]);
    }

    /**
     * Make the legacy Yii web request usable from Testbench's simulated HTTP
     * requests, so plugin controller actions resolve in `$this->get()` /
     * `$this->post()` calls.
     *
     * Three things go wrong otherwise, all because the test process is PHP CLI:
     *
     *  1. {@see \yii\base\Request::getIsConsoleRequest()} falls back to
     *     `PHP_SAPI === 'cli'` (true under PHPUnit), so Craft treats the web
     *     request as a console request and never routes it — and the plugin
     *     base resolves its *console* controller namespace.
     *  2. Yii's `getQueryParams()` reads `$_GET`, which a simulated Testbench
     *     request never populates, so action params (`id`, `uid`, …) arrive
     *     null. We copy them off the underlying Illuminate request.
     *  3. Action-request detection ({@see craft\web\Request::checkIfActionRequest})
     *     ran at request-construction time before (1) and (2) were corrected,
     *     so it must be forced to re-evaluate.
     *
     * Registered as a class-level Yii event (static, survives the per-test app
     * rebuild) so a single registration per process covers every web request.
     */
    protected function registerWebRequestTestSupport(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        \yii\base\Event::on(
            \craft\web\Application::class,
            \craft\web\Application::EVENT_BEFORE_REQUEST,
            function (): void {
                // The harness reuses the *same* Craft app instance across
                // consecutive requests in one test (see call(): it restores
                // Craft::$app after LegacyMiddleware::cleanup() nulls it, and
                // the middleware's ensureCraftApp() then reuses it rather than
                // rebuilding). LegacyMiddleware resets the `request` and `user`
                // components per request but NOT `response` — so a second
                // request inherits the first request's already-prepared Yii
                // Response, and the Illuminate response it converts to carries
                // the *first* request's body. Reset it here (this fires on
                // every EVENT_BEFORE_REQUEST) so each request formats a fresh
                // response. Without this, two POSTs in one test (e.g. the
                // compare "reuse" path) both return the first response's JSON.
                \Craft::$app->set('response', \Craft::createObject(\craft\helpers\App::webResponseConfig()));

                $request = \Craft::$app->getRequest();
                $request->setIsConsoleRequest(false);
                $request->setQueryParams($request->getIlluminateRequest()->query->all());
                $request->checkIfActionRequest(force: true);

                // Craft's UrlManager::parseRequest() — and the plugin base's
                // controller-namespace pick — short-circuit on
                // app()->runningInConsole(), which is true under PHPUnit. That
                // makes CP URL-rule routes (admin/ai/...) 404 before any rule is
                // matched. Tell Laravel this is a web request for the duration of
                // the request; call() restores it afterwards.
                $this->setLaravelRunningInConsole(false);

                // Simulated Testbench requests carry no CSRF token; turn the
                // check off so controller POST actions ($this->requirePostRequest
                // et al.) don't reject the request before the test reaches them.
                \Craft::$app->getConfig()->getGeneral()->enableCsrfProtection = false;

                // The plugin base pins the *console* controller namespace when
                // built in a CLI process (see Plugin::init()); web requests need
                // the web controllers.
                $module = \Craft::$app->getModule('craft-ai');
                if ($module !== null) {
                    $module->controllerNamespace = 'markhuot\\craftai\\controllers';
                }
            },
        );
    }

    /**
     * Flip Laravel's cached `runningInConsole()` result. The flag is a protected
     * property with no setter, so reach it through a bound closure.
     *
     * Resolve the live container rather than `$this->app`: the BEFORE_REQUEST
     * listener is registered once (static guard) and outlives the test instance
     * it was bound to, whose `->app` Testbench nulls on tear-down.
     */
    protected function setLaravelRunningInConsole(bool $value): void
    {
        $app = \Illuminate\Container\Container::getInstance();

        (function () use ($value): void {
            $this->isRunningInConsole = $value;
        })->call($app);
    }

    /**
     * Restore the legacy Craft app after a simulated HTTP request.
     *
     * {@see \CraftCms\Yii2Adapter\Http\LegacyMiddleware::cleanup()} registers an
     * `app()->terminating()` callback that nulls `Craft::$app` (and forgets the
     * container binding) once the request is handled. Laravel's test client runs
     * that terminate step inside `call()`, so by the time a test makes its
     * post-request assertions — `PreviewRequestRecord::findOne(...)` and friends —
     * `Yii::$app` is null and every ActiveRecord query blows up with
     * "Call to a member function getDb() on null". Rebuild it here so the legacy
     * app is live again for the rest of the test body.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Hold the live Craft app so we can put the *same* instance back after
        // the request — rebuilding via app()->make('Craft') would hand back a
        // fresh app whose plugin module isn't booted, leaving
        // Plugin::getInstance() null for the test's post-request assertions.
        $craftApp = \Craft::$app;

        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        if (! \Craft::$app) {
            \Craft::$app = $craftApp ?? $this->app->make('Craft');
        }

        // Undo the web-request override applied in registerWebRequestTestSupport()
        // so non-request test code sees the real (console) value again.
        $this->setLaravelRunningInConsole(true);

        return $response;
    }

    /**
     * Log in a Craft user for the test.
     *
     * loginByUserId() routes to Laravel's Auth::loginUsingId(), which doesn't
     * invalidate the legacy web User's cached `_identity`. So getId() /
     * getIdentity() keep returning whatever was cached before login (null at
     * boot) and user-scoped code mis-resolves the current user. Reset the cache
     * so the next read re-derives the identity from the authenticated guard.
     */
    protected function loginCraftUser(int $userId): void
    {
        \Craft::$app->getUser()->loginByUserId($userId);

        $user = \Craft::$app->getUser();
        $ref = new ReflectionObject($user);
        while ($ref !== false && ! $ref->hasProperty('_identity')) {
            $ref = $ref->getParentClass();
        }

        if ($ref !== false) {
            $property = $ref->getProperty('_identity');
            $property->setAccessible(true);
            $property->setValue($user, false);
        }
    }
}
