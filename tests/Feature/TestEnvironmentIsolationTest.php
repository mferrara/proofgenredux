<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Test environment isolation contract
|--------------------------------------------------------------------------
|
| These tests pin the guardrails installed by tests/TestCase.php and
| phpunit.xml. They intentionally assert the *absence* of operator-owned
| resources (database, credentials, network) in the test environment.
|
*/

it('runs against an in-memory sqlite database in the testing environment', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.driver'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('database.connections.sqlite.url'))->toBeEmpty()
        ->and(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('mail.default'))->toBe('array');
});

it('does not inherit operator sftp credentials or sample download settings', function () {
    expect(config('proofgen.sftp.host'))->toBeEmpty()
        ->and(config('proofgen.sftp.private_key'))->toBeEmpty()
        ->and(config('proofgen.sftp.path'))->toBeEmpty()
        ->and(config('proofgen.sftp.web_images_path'))->toBeEmpty()
        ->and(config('proofgen.sftp.highres_images_path'))->toBeEmpty()
        ->and(config('proofgen.auto_download_sample_images'))->toBeFalse();
});

it('blocks stray outbound http requests', function () {
    expect(Http::preventingStrayRequests())->toBeTrue();

    Http::get('https://example.invalid/not-faked');
})->throws(StrayRequestException::class);

it('still allows explicitly faked http requests', function () {
    Http::fake([
        'https://example.invalid/faked' => Http::response(['ok' => true]),
    ]);

    expect(Http::get('https://example.invalid/faked')->json('ok'))->toBeTrue();
});

it('rejects a cached configuration before evaluating its php', function () {
    $cache = tempnam(sys_get_temp_dir(), 'proofgen-test-cache-');
    file_put_contents($cache, '<?php throw new RuntimeException("CACHE_WAS_EXECUTED");');

    try {
        $script = 'require "vendor/autoload.php"; '
            .'$test = new class("probe") extends \\Tests\\TestCase {}; '
            .'try { $test->createApplication(); exit(2); } '
            .'catch (RuntimeException $e) { echo $e->getMessage(); }';
        $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $cache,
        ]);
        $process->mustRun();

        expect($process->getOutput())->toContain('Refusing to load cached configuration')
            ->not->toContain('CACHE_WAS_EXECUTED');
    } finally {
        unlink($cache);
    }
});

it('rejects unsafe database settings before a provider can connect', function () {
    $script = 'require "vendor/autoload.php"; '
        .'$test = new class("probe") extends \\Tests\\TestCase {}; '
        .'try { $test->createApplication(); exit(2); } '
        .'catch (RuntimeException $e) { echo $e->getMessage(); }';
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => 'storage/framework/testing/absent-probe-config.php',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => 'never_connect',
        'DB_URL' => '',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '1',
    ]);
    $process->mustRun();

    expect($process->getOutput())->toContain('Refusing to run destructive test setup')
        ->not->toContain('SQLSTATE');
});

it('pins the named upload connection before service providers register', function () {
    $script = <<<'PHP'
require 'vendor/autoload.php';
$test = new class('probe') extends \Tests\TestCase {
    protected function guardAgainstUnsafeTestEnvironment(?\Illuminate\Foundation\Application $app = null): void
    {
        parent::guardAgainstUnsafeTestEnvironment($app);
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class, function ($app) {
            if ($app['config']->get('queue.connections.uploads.driver') !== 'sync') {
                throw new RuntimeException('Upload connection was not isolated before provider registration.');
            }
            echo 'UPLOAD_CONNECTION_ISOLATED';
        });
    }
};
$test->createApplication();
PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => 'storage/framework/testing/absent-probe-config.php',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
    ]);
    $process->mustRun();

    expect($process->getOutput())->toContain('UPLOAD_CONNECTION_ISOLATED');
});
