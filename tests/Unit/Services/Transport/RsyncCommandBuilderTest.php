<?php

namespace Tests\Unit\Services\Transport;

use App\Services\Transport\RsyncCommandBuilder;
use App\Services\Transport\RsyncFailedException;
use App\Services\Transport\RsyncRunner;
use App\Services\Transport\RsyncRunResult;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Command construction + runner behaviour. The argv assertions are the real
 * contract: callers execute through {@see RsyncRunner} without a local shell,
 * and the string form (build()) must survive one local shell unchanged.
 */
class RsyncCommandBuilderTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/rsync_builder_test_'.uniqid());
        File::makeDirectory($this->tempPath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    public function test_local_driver_argv_has_no_ssh_and_preserves_paths(): void
    {
        config(['proofgen.sftp.driver' => 'local']);

        $argv = RsyncCommandBuilder::argv("/tmp/source it's/", '/tmp/dest dir', "show one/it's", false);

        $this->assertSame('rsync', $argv[0]);
        $this->assertContains('-avz', $argv);
        $this->assertContains('-ii', $argv, 'repeated itemize is what makes rsync report unchanged files');
        $this->assertContains('--out-format='.RsyncCommandBuilder::OUT_FORMAT, $argv);
        $this->assertNotContains('--delete', $argv);
        $this->assertNotContains('-e', $argv);
        $this->assertSame("/tmp/source it's/", $argv[count($argv) - 2]);
        $this->assertSame("/tmp/dest dir/show one/it's", $argv[count($argv) - 1]);
    }

    public function test_dry_run_flag_is_only_added_when_requested(): void
    {
        config(['proofgen.sftp.driver' => 'local']);

        $dry = RsyncCommandBuilder::argv('/src/', '/dest', 'sub', true);
        $real = RsyncCommandBuilder::argv('/src/', '/dest', 'sub', false);

        $this->assertContains('--dry-run', $dry);
        $this->assertNotContains('--dry-run', $real);

        // Trailing-directory semantics: the source keeps its trailing slash.
        $this->assertSame('/src/', $dry[count($dry) - 2]);
        $this->assertSame('/dest/sub', $dry[count($dry) - 1]);
    }

    public function test_real_rsync_reports_unchanged_files_as_synced_evidence(): void
    {
        config(['proofgen.sftp.driver' => 'local']);

        $source = $this->tempPath.'/src/';
        $dest = $this->tempPath.'/dst';
        File::makeDirectory($source, 0755, true);
        File::put($source.'SHOW1_00001_thm.jpg', 'unchanged bytes');

        $runner = new RsyncRunner;

        $first = $runner->run(RsyncCommandBuilder::argv($source, $dest, '', false));
        $this->assertContains('SHOW1_00001_thm.jpg', $first->transferredFiles());

        // Second run is a no-op; only the repeated -i unchanged entries prove
        // that rsync saw and confirmed the file at the destination.
        $second = $runner->run(RsyncCommandBuilder::argv($source, $dest, '', false));
        $this->assertSame([], $second->transferredFiles(), 'the no-op run transfers nothing');
        $this->assertContains('SHOW1_00001_thm.jpg', $second->syncedFiles(), '-ii must report unchanged files');
    }

    public function test_sftp_driver_quotes_key_for_rsync_rsh_and_includes_port(): void
    {
        config([
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.username' => 'forge',
            'proofgen.sftp.host' => 'example.com',
            'proofgen.sftp.private_key' => "/tmp/Certs/it's key.pem",
            'proofgen.sftp.port' => 2222,
        ]);

        $argv = RsyncCommandBuilder::argv('/tmp/source dir/', '/srv/proofs', "buck's show/101", false);

        $eIndex = array_search('-e', $argv, true);
        $this->assertNotFalse($eIndex);
        $this->assertSame(
            'ssh -o ConnectTimeout=15 -o BatchMode=yes -i "/tmp/Certs/it\'s key.pem" -p 2222',
            $argv[$eIndex + 1]
        );
        $this->assertStringNotContainsString('StrictHostKeyChecking', $argv[$eIndex + 1], 'host-key verification must stay enabled');
        $this->assertSame("forge@example.com:/srv/proofs/buck's show/101", $argv[count($argv) - 1]);
        $this->assertSame('/tmp/source dir/', $argv[count($argv) - 2]);
    }

    public function test_sftp_driver_omits_ssh_port_when_default_or_unset(): void
    {
        config([
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.username' => 'forge',
            'proofgen.sftp.host' => 'example.com',
            'proofgen.sftp.private_key' => '/home/forge/.ssh/id_ed25519',
            'proofgen.sftp.port' => 22,
        ]);

        $argv = RsyncCommandBuilder::argv('/src/', '/srv/proofs', 'SHOW1', false);
        $eIndex = array_search('-e', $argv, true);

        $this->assertSame('ssh -o ConnectTimeout=15 -o BatchMode=yes -i /home/forge/.ssh/id_ed25519', $argv[$eIndex + 1]);
    }

    public function test_sftp_key_with_double_quote_uses_single_quotes(): void
    {
        config([
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.username' => 'forge',
            'proofgen.sftp.host' => 'example.com',
            'proofgen.sftp.private_key' => '/tmp/it is "fine".pem',
            'proofgen.sftp.port' => 22,
        ]);

        $argv = RsyncCommandBuilder::argv('/src/', '/srv/proofs', 'SHOW1', false);
        $eIndex = array_search('-e', $argv, true);

        $this->assertSame("ssh -o ConnectTimeout=15 -o BatchMode=yes -i '/tmp/it is \"fine\".pem'", $argv[$eIndex + 1]);
    }

    public function test_sftp_key_with_both_quote_types_is_rejected_instead_of_misparsed(): void
    {
        config([
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.username' => 'forge',
            'proofgen.sftp.host' => 'example.com',
            'proofgen.sftp.private_key' => '/tmp/it\'s "weird".pem',
            'proofgen.sftp.port' => 22,
        ]);

        $this->expectException(InvalidArgumentException::class);

        RsyncCommandBuilder::argv('/src/', '/srv/proofs', 'SHOW1', false);
    }

    public function test_build_string_round_trips_through_local_shell_with_paths_intact(): void
    {
        config(['proofgen.sftp.driver' => 'local']);

        $argv = RsyncCommandBuilder::argv("/tmp/source it's/", '/tmp/dest dir', "show one/it's", true);
        $logged = $this->runBuiltCommandThroughShell(RsyncCommandBuilder::build(
            "/tmp/source it's/",
            '/tmp/dest dir',
            "show one/it's",
            true,
        ));

        $this->assertSame(array_slice($argv, 1), $logged);
    }

    public function test_sftp_build_string_round_trips_through_local_shell(): void
    {
        config([
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.username' => 'forge',
            'proofgen.sftp.host' => 'example.com',
            'proofgen.sftp.private_key' => "/tmp/Certs/it's key.pem",
            'proofgen.sftp.port' => 2222,
        ]);

        $argv = RsyncCommandBuilder::argv('/tmp/src/', '/srv/proofs', "buck's show/101", false);
        $logged = $this->runBuiltCommandThroughShell(RsyncCommandBuilder::build(
            '/tmp/src/',
            '/srv/proofs',
            "buck's show/101",
            false,
        ));

        $this->assertSame(array_slice($argv, 1), $logged);

        $eIndex = array_search('-e', $logged, true);
        $this->assertSame('ssh -o ConnectTimeout=15 -o BatchMode=yes -i "/tmp/Certs/it\'s key.pem" -p 2222', $logged[$eIndex + 1]);
        $this->assertSame("forge@example.com:/srv/proofs/buck's show/101", $logged[count($logged) - 1]);
    }

    public function test_runner_throws_on_nonzero_exit_with_bounded_stderr_and_no_full_command(): void
    {
        $tailMarker = 'STDERR_TAIL_MARKER';
        $long = str_repeat('E', 6000).$tailMarker;
        $fake = $this->writeFakeBinary(
            "#!/bin/bash\nprintf '>f+++++++++ 101/new file.jpg\\n'\nprintf '%s' ".escapeshellarg($long)." >&2\nexit 23\n"
        );

        try {
            (new RsyncRunner($fake))->run(['rsync', '-avz', '/tmp/source dir/', '/tmp/dest dir']);
            $this->fail('Expected RsyncFailedException.');
        } catch (RsyncFailedException $e) {
            $this->assertStringContainsString('exit code 23', $e->getMessage());
            $this->assertStringContainsString($tailMarker, $e->getMessage());
            $this->assertLessThan(5000, strlen($e->getMessage()), 'stderr diagnostic must be bounded');
            $this->assertStringNotContainsString('/tmp/source dir/', $e->getMessage(), 'full command must not leak into the error');
        }
    }

    public function test_runner_times_out_instead_of_hanging(): void
    {
        $fake = $this->writeFakeBinary("#!/bin/bash\nsleep 5\n");
        $marker = 'TIMEOUT_COMMAND_MARKER_9f3a';

        try {
            (new RsyncRunner($fake, 1))->run(['rsync', '-avz', '/tmp/'.$marker.'/src/', '/tmp/'.$marker.'/dest']);
            $this->fail('Expected RsyncFailedException.');
        } catch (RsyncFailedException $e) {
            $this->assertStringContainsString('timed out after 1 seconds', $e->getMessage());
            $this->assertStringNotContainsString('/src/', $e->getMessage(), 'argv must not leak into timeout errors');
            $this->assertStringNotContainsString($marker, $e->getMessage(), 'the full command must not leak into timeout errors');
            $this->assertStringNotContainsString($fake, $e->getMessage());
        }
    }

    public function test_runner_start_failure_does_not_leak_command_text(): void
    {
        $marker = 'START_FAILURE_COMMAND_MARKER_4b7c';
        $missingBinary = $this->tempPath.'/missing-'.$marker.'/rsync';

        try {
            (new RsyncRunner($missingBinary))->run(['rsync', '-avz', '-e', 'ssh -i /tmp/'.$marker.'/key', '/tmp/src/', '/tmp/dest/']);
            $this->fail('Expected RsyncFailedException.');
        } catch (RsyncFailedException $e) {
            $this->assertStringContainsString('could not be started', $e->getMessage());
            $this->assertStringNotContainsString($marker, $e->getMessage(), 'the full command must not leak into start errors');
        }
    }

    public function test_run_result_parses_itemized_output_deterministically(): void
    {
        $stdout = implode("\n", [
            'sending incremental file list',
            '.d          ./',
            '>f+++++++++ 101/a b.jpg',
            'cd+++++++++ 101/sub/',
            '>f.st...... 101/changed.jpg',
            '.f......... 101/attr-only.jpg',
            '.f          101/up-to-date.jpg',
            '>f+++++++++ 101/other.jpg',
            '',
            'sent 123 bytes  received 45 bytes  336.00 bytes/sec',
            'total size is 10  speedup is 0.06 (DRY RUN)',
        ]);

        $result = new RsyncRunResult(0, $stdout, '');

        $this->assertSame(
            ['101/a b.jpg', '101/changed.jpg', '101/other.jpg'],
            $result->transferredFiles(),
            'only content transfers count as transfers'
        );

        $this->assertSame(
            ['101/a b.jpg', '101/changed.jpg', '101/attr-only.jpg', '101/up-to-date.jpg', '101/other.jpg'],
            $result->syncedFiles(),
            'unchanged regular files are successful-sync evidence'
        );
    }

    public function test_run_result_ignores_directory_deletion_and_non_regular_entries(): void
    {
        $stdout = implode("\n", [
            '*deleting   101/removed.jpg',
            '>f+++++++++ 101/kept.jpg',
            'cL+++++++++ 101/link.jpg',
            '>D+++++++++ 101/device',
        ]);

        $result = new RsyncRunResult(0, $stdout, '');

        $this->assertSame(['101/kept.jpg'], $result->transferredFiles());
        $this->assertSame(['101/kept.jpg'], $result->syncedFiles());
    }

    /**
     * @return array<int, string> argv the fake rsync received (binary excluded)
     */
    private function runBuiltCommandThroughShell(string $builtCommand): array
    {
        $binDir = $this->tempPath.'/fake-rsync-bin';
        File::makeDirectory($binDir, 0755, true);
        $logFile = $this->tempPath.'/rsync-argv.log';

        $this->writeFakeBinary(<<<'SH'
#!/bin/bash
: > "${FAKE_RSYNC_LOG}"
for a in "$@"; do printf '%s\0' "$a" >> "${FAKE_RSYNC_LOG}"; done
exit 0
SH, $binDir.'/rsync');

        $command = 'PATH='.escapeshellarg($binDir).':"$PATH" FAKE_RSYNC_LOG='.escapeshellarg($logFile).' '.$builtCommand;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, 'built command failed under /bin/sh');

        $raw = (string) file_get_contents($logFile);

        return array_values(array_filter(explode("\0", $raw), fn (string $arg) => $arg !== ''));
    }

    private function writeFakeBinary(string $script, ?string $path = null): string
    {
        $path ??= $this->tempPath.'/fake-rsync';

        File::put($path, $script);
        chmod($path, 0755);

        return $path;
    }
}
