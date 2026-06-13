<?php

namespace Tests;

use Craft;
use craft\elements\User;
use CraftCms\Aliases\AliasesServiceProvider;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Providers\AppServiceProvider;
use CraftCms\Cms\Providers\CraftServiceProvider;
use CraftCms\DependencyAwareCache\CacheServiceProvider;
use CraftCms\Yii2Adapter\Yii2ServiceProvider;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Tinker\TinkerServiceProvider;
use Laravel\Wayfinder\WayfinderServiceProvider;
use ReflectionClass;
use Yiisoft\Aliases\Aliases;

/**
 * Base test case for the craft-ai plugin under Craft 6.
 *
 * Extends Craft's first-party {@see PluginTestCase} (Orchestra Testbench +
 * LazilyRefreshDatabase + the InstallsPlugin trait, which installs and enables
 * the plugin from this package's composer.json `extra`).
 */
class TestCase extends PluginTestCase
{
    /**
     * The plugin runs through craftcms/yii2-adapter, so the test app needs the
     * same provider stack Craft's own suite boots — most importantly the
     * adapter's Yii2ServiceProvider, which loads the legacy `\Craft` class and
     * binds the `Craft` service the adapter Plugin base resolves in its
     * static create(). Testbench doesn't run package auto-discovery, so they're
     * listed explicitly. Aliases + dependency-aware cache come before Craft;
     * Wayfinder after, mirroring Craft's testbench.yaml.
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
     * Pin the `@craftcms` path alias on the shared Aliases instance the moment
     * it's built, so Craft's IconServiceProvider can resolve
     * `@craftcms/resources/icons/aliases.php` regardless of provider boot order.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $cmsRoot = dirname((new ReflectionClass(AppServiceProvider::class))->getFileName(), 3);

        // Runtime dirs the legacy bootstrap points its @templates/@storage
        // aliases at. Created here so fresh checkouts / CI don't need committed
        // empty directories.
        @mkdir(__DIR__.'/.craft/templates', 0777, true);
        @mkdir(__DIR__.'/.craft/storage', 0777, true);

        // Craft registers the `craft` guard/provider but doesn't make it the
        // default (a real install's config/auth.php does). Without this the
        // default `web` guard returns a generic Laravel user and Craft's
        // permission Gate hooks reject it.
        $app['config']->set('auth.defaults.guard', 'craft');

        $app->resolving(Aliases::class, function (Aliases $aliases) use ($cmsRoot): void {
            $aliases->set('@craftcms', $cmsRoot);
            $aliases->set('@resources', "$cmsRoot/resources");
            // The legacy adapter bootstrap requires @templates to already be
            // defined (its Aliases::get() throws rather than using a default).
            $aliases->set('@templates', __DIR__.'/.craft/templates');
            $aliases->set('@storage', __DIR__.'/.craft/storage');
        });

        // Craft's model factories live in CraftCms\Cms\Database\Factories\*Factory,
        // not Laravel's default Database\Factories namespace. Craft's own suite
        // wires this through the Testbench workbench's factory discovery; point
        // the resolver there directly so Model::factory() works in plugin tests.
        Factory::guessFactoryNamesUsing(
            static fn (string $model): string => 'CraftCms\\Cms\\Database\\Factories\\'.class_basename($model).'Factory',
        );

        parent::getEnvironmentSetUp($app);
    }

    /**
     * The bundled plugin's Plugin class extends the Yii-style craft\base\Plugin
     * (registers in init(), no native boot(Plugins) method). The stock
     * bootPluginProvider() calls ->boot($plugins), which only exists on the
     * native CraftCms\Cms\Plugin\Plugin. Override to just load the enabled
     * plugins; instantiating the plugin runs its init() registration.
     */
    public function bootPluginProvider(): void
    {
        $this->app->get(Plugins::class)->loadPlugins();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tool execution runs Craft permission checks, so default to an admin
        // identity (the user the Install migration created). Tests verifying
        // permission denial can override the identity in the test body.
        $admin = User::find()->admin()->one();
        Craft::$app->getUser()->loginByUserId((int) $admin->id);

        // Default the shared ToolContext to the CP surface, mirroring the
        // primary in-app chat path.
        Craft::$container->get(ToolContext::class)->begin('test-session', null, ClientType::CP);
    }
}
