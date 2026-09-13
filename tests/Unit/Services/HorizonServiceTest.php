<?php

namespace Tests\Unit\Services;

use App\Services\HorizonService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HorizonServiceTest extends TestCase
{
    /** @var string[] */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    private function tempDir(): string
    {
        $path = storage_path('app/horizon_service_test_'.uniqid());
        File::makeDirectory($path, 0755, true);
        $this->tempPaths[] = $path;

        return $path;
    }

    public function test_start_is_idempotent_when_horizon_is_already_running(): void
    {
        $service = new FakeHorizonService;
        $service->runningResponses = [true];

        $this->assertTrue($service->start());
        $this->assertSame(1, $service->isRunningCalls, 'the pre-flight status check should run');
        $this->assertSame(0, $service->launchCount, 'no second master supervisor should be spawned');
    }

    public function test_start_launches_detached_and_reports_success_once_horizon_is_ready(): void
    {
        $service = new FakeHorizonService;
        // Pre-flight check, then two failed probes, then success.
        $service->runningResponses = [false, false, false, true];

        $this->assertTrue($service->start());
        $this->assertSame(1, $service->launchCount);
        $this->assertNotNull($service->launchedCommand);
        $this->assertStringContainsString('nohup', $service->launchedCommand);
        $this->assertStringEndsWith('&', $service->launchedCommand);
    }

    public function test_start_returns_false_without_waiting_when_the_launcher_fails(): void
    {
        $service = new FakeHorizonService;
        $service->runningResponses = [false];
        $service->launchResult = false;

        $this->assertFalse($service->start());
        $this->assertSame(1, $service->launchCount);
    }

    public function test_start_is_bounded_when_horizon_never_reports_running(): void
    {
        $service = new FakeHorizonService;
        $service->runningResponses = array_fill(0, 100, false);
        $service->readinessTimeout = 0.05;
        $service->pollMicroseconds = 1000;

        $startedAt = microtime(true);
        $this->assertFalse($service->start());
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(2.0, $elapsed, 'readiness check should be bounded by readinessTimeoutSeconds()');
        $this->assertSame(1, $service->launchCount);
    }

    public function test_build_start_command_quotes_paths_containing_spaces(): void
    {
        $service = new class extends HorizonService
        {
            protected function phpBinary(): string
            {
                return '/Applications/My Herd/php84/bin/php';
            }

            protected function basePath(): string
            {
                return '/Users/Some One/Herd/proofgen redux';
            }

            protected function horizonLogPath(): string
            {
                return '/Users/Some One/Herd/proofgen redux/storage/logs/horizon.log';
            }

            public function exposeCommand(): string
            {
                return $this->buildStartCommand();
            }
        };

        $command = $service->exposeCommand();

        $this->assertStringContainsString(escapeshellarg('/Applications/My Herd/php84/bin/php'), $command);
        $this->assertStringContainsString(escapeshellarg('/Users/Some One/Herd/proofgen redux'), $command);
        $this->assertStringContainsString(escapeshellarg('/Users/Some One/Herd/proofgen redux/storage/logs/horizon.log'), $command);
        $this->assertStringContainsString('< /dev/null', $command, 'stdin must be detached');
        $this->assertStringEndsWith('&', $command, 'the launcher must be backgrounded');
    }

    public function test_launch_detached_does_not_block_on_the_background_process(): void
    {
        $directory = $this->tempDir();
        $marker = $directory.'/started.marker';
        $log = $directory.'/horizon.log';

        $service = new class($marker, $log) extends HorizonService
        {
            public function __construct(
                private string $marker,
                private string $log,
            ) {}

            protected function basePath(): string
            {
                return dirname($this->log);
            }

            protected function horizonLogPath(): string
            {
                return $this->log;
            }

            protected function buildStartCommand(): string
            {
                // Synthetic launcher: the real Horizon command is never run. It
                // sleeps before writing its marker so the test can prove the
                // launcher returns immediately while the child keeps running.
                return sprintf(
                    '(sleep 2; printf started > %s) >> %s 2>&1 < /dev/null &',
                    escapeshellarg($this->marker),
                    escapeshellarg($this->log)
                );
            }

            public float $launchSeconds = 0;

            protected function launchDetached(string $command): bool
            {
                $before = microtime(true);
                $result = parent::launchDetached($command);
                $this->launchSeconds = microtime(true) - $before;

                return $result;
            }

            public function isRunning(): bool
            {
                return file_exists($this->marker);
            }

            protected function readinessTimeoutSeconds(): float
            {
                return 3.0;
            }

            protected function readinessPollMicroseconds(): int
            {
                return 20000;
            }
        };

        $startedAt = microtime(true);
        $this->assertTrue($service->start());
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(1.0, $service->launchSeconds, 'launcher must return before the two second child finishes');
        $this->assertFileExists($marker);
        $this->assertSame('started', file_get_contents($marker));
        $this->assertLessThan(3.0, $elapsed, 'proc_open()/proc_close() must not wait for the detached child');
    }

    public function test_launch_detached_returns_false_when_the_launcher_exits_non_zero(): void
    {
        $directory = $this->tempDir();

        $service = new class($directory) extends HorizonService
        {
            public function __construct(private string $directory) {}

            protected function basePath(): string
            {
                return $this->directory;
            }

            protected function horizonLogPath(): string
            {
                return $this->directory.'/horizon.log';
            }

            protected function buildStartCommand(): string
            {
                return 'exit 7';
            }

            public function isRunning(): bool
            {
                return false;
            }

            protected function readinessTimeoutSeconds(): float
            {
                return 0.01;
            }
        };

        $this->assertFalse($service->start());
    }
}

/**
 * Test double that replaces the process boundary. It records what start() asks
 * the launcher to do instead of spawning a real Horizon master supervisor.
 */
class FakeHorizonService extends HorizonService
{
    public string $binary = '/usr/bin/php';

    public string $base = '/tmp/proofgen';

    public string $log = '/tmp/proofgen/storage/logs/horizon.log';

    /** @var bool[] */
    public array $runningResponses = [];

    public int $isRunningCalls = 0;

    public bool $launchResult = true;

    public int $launchCount = 0;

    public ?string $launchedCommand = null;

    public float $readinessTimeout = 0.25;

    public int $pollMicroseconds = 1000;

    protected function phpBinary(): string
    {
        return $this->binary;
    }

    protected function basePath(): string
    {
        return $this->base;
    }

    protected function horizonLogPath(): string
    {
        return $this->log;
    }

    protected function readinessTimeoutSeconds(): float
    {
        return $this->readinessTimeout;
    }

    protected function readinessPollMicroseconds(): int
    {
        return $this->pollMicroseconds;
    }

    public function isRunning(): bool
    {
        $this->isRunningCalls++;

        if ($this->runningResponses === []) {
            return false;
        }

        return (bool) array_shift($this->runningResponses);
    }

    protected function launchDetached(string $command): bool
    {
        $this->launchCount++;
        $this->launchedCommand = $command;

        return $this->launchResult;
    }
}
