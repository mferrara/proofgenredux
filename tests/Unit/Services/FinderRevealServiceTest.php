<?php

namespace Tests\Unit\Services;

use App\Services\FinderRevealService;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class FinderRevealServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => '/tmp/proofgen-fullsize-finder-test',
            'throw' => true,
        ]]);
        config(['filesystems.disks.archive' => [
            'driver' => 'local',
            'root' => '/tmp/proofgen-archive-finder-test',
            'throw' => true,
        ]]);
    }

    public function test_reveal_shells_out_to_open_when_path_is_within_known_disk(): void
    {
        Process::fake();

        $absolute = '/tmp/proofgen-fullsize-finder-test/SHOW1/101/_import_conflicts/IMG_0001.jpg';

        app(FinderRevealService::class)->reveal($absolute);

        Process::assertRan(function ($process) use ($absolute) {
            $command = $process->command;
            if (is_array($command)) {
                return $command === ['open', '-R', $absolute];
            }

            return is_string($command)
                && str_contains($command, 'open')
                && str_contains($command, '-R')
                && str_contains($command, $absolute);
        });
    }

    public function test_reveal_accepts_paths_under_archive_disk(): void
    {
        Process::fake();

        $absolute = '/tmp/proofgen-archive-finder-test/SHOW1/101/_conflicts/IMG_0002.jpg';
        app(FinderRevealService::class)->reveal($absolute);

        Process::assertRan(function ($process) use ($absolute) {
            $command = $process->command;
            if (is_array($command)) {
                return in_array($absolute, $command, true);
            }

            return is_string($command) && str_contains($command, $absolute);
        });
    }

    public function test_reveal_throws_for_path_outside_known_disks(): void
    {
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to reveal path outside known disks');

        app(FinderRevealService::class)->reveal('/etc/passwd');
    }

    public function test_reveal_throws_for_empty_path(): void
    {
        Process::fake();

        $this->expectException(RuntimeException::class);

        app(FinderRevealService::class)->reveal('');
    }

    public function test_reveal_does_not_match_path_with_shared_prefix_outside_root(): void
    {
        // /tmp/proofgen-fullsize-finder-test-other should NOT be allowed even though
        // it shares a prefix with the configured root.
        Process::fake();

        $this->expectException(RuntimeException::class);

        app(FinderRevealService::class)->reveal('/tmp/proofgen-fullsize-finder-test-other/file.jpg');
    }
}
