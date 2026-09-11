<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Environment variables that must be owned by phpunit.xml.
     *
     * PHPUnit's force="true" writes to $_ENV and putenv() but not $_SERVER.
     * Laravel's dotenv repository reads $_SERVER before $_ENV, so an inherited
     * shell value (or a parent process that exported DB_CONNECTION=mysql) would
     * otherwise win. Mirroring makes the phpunit.xml values authoritative.
     */
    protected const TEST_ENVIRONMENT_VARIABLES = [
        'APP_ENV',
        'APP_KEY',
        'APP_CONFIG_CACHE',
        'APP_MAINTENANCE_DRIVER',
        'APP_ROUTES_CACHE',
        'AUTO_DOWNLOAD_SAMPLE_IMAGES',
        'BCRYPT_ROUNDS',
        'CACHE_STORE',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_URL',
        'MAIL_MAILER',
        'PULSE_ENABLED',
        'QUEUE_CONNECTION',
        'SESSION_DRIVER',
        'TELESCOPE_ENABLED',
        'SFTP_HOSTNAME',
        'SFTP_HIGHRES_IMAGES_PATH',
        'SFTP_PATHTOPRIVATEKEY',
        'SFTP_PORT',
        'SFTP_PROOFSPATH',
        'SFTP_USERNAME',
        'SFTP_WEB_IMAGES_PATH',
    ];

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);

        // Reject cached PHP before it is evaluated, and reject unsafe database
        // configuration before any service provider can open a connection.
        $app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
            if ($app->configurationIsCached()) {
                throw new RuntimeException('Refusing to load cached configuration during tests. Remove the test configuration cache.');
            }
        });
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $this->guardAgainstUnsafeTestEnvironment($app);
            // Pin named upload dispatches before providers or database setup can
            // enqueue work; QUEUE_CONNECTION=sync only covers the default.
            $app['config']->set('queue.connections.uploads.driver', 'sync');
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        $this->forceTestEnvironmentValues();

        parent::setUp();

        if ($this->app !== null) {
            // An un-faked outbound HTTP request is a test-isolation bug.
            // Tests that intentionally exercise HTTP call Http::fake() themselves.
            Http::preventStrayRequests();
        }
    }

    /**
     * Fail closed before RefreshDatabase / DatabaseMigrations can touch a
     * database. Runs after the application is booted (so config() is available)
     * but before parent::setUpTraits() calls refreshDatabase().
     */
    protected function setUpTraits()
    {
        $this->guardAgainstUnsafeTestEnvironment();

        return parent::setUpTraits();
    }

    /**
     * Copy the phpunit.xml-owned environment values from $_ENV into $_SERVER
     * (and putenv) before Laravel boots and reads the environment.
     */
    protected function forceTestEnvironmentValues(): void
    {
        foreach (static::TEST_ENVIRONMENT_VARIABLES as $name) {
            if (! array_key_exists($name, $_ENV)) {
                continue;
            }

            $value = (string) $_ENV[$name];

            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    /**
     * Refuse to run if the suite is not pointed at a throwaway database.
     *
     * @throws RuntimeException
     */
    protected function guardAgainstUnsafeTestEnvironment(?Application $app = null): void
    {
        $app ??= $this->app;
        if ($app === null) {
            return;
        }

        if ($app->environment() !== 'testing') {
            throw new RuntimeException(
                'Refusing to run tests outside the "testing" environment (APP_ENV='.
                $app->environment().'). Check phpunit.xml and any cached configuration.'
            );
        }

        if ($app->configurationIsCached()) {
            throw new RuntimeException(
                'Refusing to run tests with cached configuration because it can bake in real '.
                'environment values. Remove the isolated test configuration cache before running the suite.'
            );
        }

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        $url = $app['config']->get("database.connections.{$connection}.url");

        if ($connection !== 'sqlite' || $database !== ':memory:' || ! empty($url)) {
            throw new RuntimeException(sprintf(
                'Refusing to run destructive test setup against a non-isolated database '.
                '(default=%s, database=%s, url=%s). Tests must use sqlite :memory: (see phpunit.xml).',
                (string) $connection,
                (string) $database,
                empty($url) ? 'empty' : 'configured',
            ));
        }
    }
}
