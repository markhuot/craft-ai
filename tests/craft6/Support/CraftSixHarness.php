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
        static $booted = false;

        $plugins = $this->app->get(Plugins::class);
        $plugins->loadPlugins();

        if (! $booted && $plugins->getPlugin('craft-ai') === null) {
            $booted = true;
            $plugins->createPlugin('craft-ai');
        }
    }
}
