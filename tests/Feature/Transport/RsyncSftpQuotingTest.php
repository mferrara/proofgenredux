<?php

namespace Tests\Feature\Transport;

use App\Services\Transport\RsyncCommandBuilder;
use App\Services\Transport\RsyncFailedException;
use App\Services\Transport\RsyncRunner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Local-only proof that the SFTP command survives both shell boundaries.
 *
 * The real `rsync` binary is used (no remote connection is made) with a fake
 * `ssh` first on PATH. The fake records the exact argv rsync hands to the
 * remote shell, which shows:
 *
 *  - rsync's `-e` splitter receives the private key path as one argument even
 *    when it contains spaces/apostrophes,
 *  - the `-p` port and the `ConnectTimeout=15` / `BatchMode=yes` SSH options
 *    are forwarded to ssh (host-key verification must stay untouched),
 *  - the `user@host:path` destination stays one rsync argument and rsync
 *    backslash-escapes it for the remote shell.
 */
class RsyncSftpQuotingTest extends TestCase
{
    private string $tempPath;

    private string $originalPath;

    private ?string $originalServerPath = null;

    private ?string $originalEnvPath = null;

    private ?string $originalServerSshLog = null;

    private ?string $originalEnvSshLog = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/opt/homebrew/bin/rsync') && ! is_executable('/usr/bin/rsync')) {
            $this->markTestSkipped('rsync binary not found; required for SFTP quoting tests.');
        }

        $this->tempPath = storage_path('app/rsync_sftp_quoting_'.uniqid());
        File::makeDirectory($this->tempPath.'/bin', 0755, true);

        $this->originalPath = (string) getenv('PATH');
        $this->originalServerPath = $_SERVER['PATH'] ?? null;
        $this->originalEnvPath = $_ENV['PATH'] ?? null;
        $this->originalServerSshLog = $_SERVER['FAKE_SSH_LOG'] ?? null;
        $this->originalEnvSshLog = $_ENV['FAKE_SSH_LOG'] ?? null;
    }

    protected function tearDown(): void
    {
        if (isset($this->originalPath)) {
            putenv('PATH='.$this->originalPath);
            $this->restoreEnvValue('PATH', $this->originalServerPath, $this->originalEnvPath);
        }

        putenv('FAKE_SSH_LOG');
        $this->restoreEnvValue('FAKE_SSH_LOG', $this->originalServerSshLog, $this->originalEnvSshLog);

        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    private function restoreEnvValue(string $name, ?string $serverValue, ?string $envValue): void
    {
        if ($serverValue === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $serverValue;
        }

        if ($envValue === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $envValue;
        }
    }

    public function test_rsync_passes_key_port_and_escaped_remote_path_to_ssh(): void
    {
        $logFile = $this->tempPath.'/ssh-argv.log';
        $ssh = $this->tempPath.'/bin/ssh';

        File::put($ssh, <<<'SH'
#!/bin/bash
: > "${FAKE_SSH_LOG}"
for a in "$@"; do printf '%s\0' "$a" >> "${FAKE_SSH_LOG}"; done
exit 255
SH);
        chmod($ssh, 0755);

        $newPath = $this->tempPath.'/bin'.PATH_SEPARATOR.$this->originalPath;
        putenv('PATH='.$newPath);
        // Symfony Process builds its child environment from $_ENV + $_SERVER,
        // so putenv() alone is not enough.
        $_SERVER['PATH'] = $newPath;
        $_ENV['PATH'] = $newPath;

        putenv('FAKE_SSH_LOG='.$logFile);
        $_SERVER['FAKE_SSH_LOG'] = $logFile;
        $_ENV['FAKE_SSH_LOG'] = $logFile;

        config([
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.username' => 'forge',
            // Loopback keeps a misconfigured test from reaching the network; the
            // fake ssh always intercepts before any real connection is attempted.
            'proofgen.sftp.host' => '127.0.0.1',
            'proofgen.sftp.private_key' => "/tmp/Certs/it's key dir/my key.pem",
            'proofgen.sftp.port' => 2222,
        ]);

        $argv = RsyncCommandBuilder::argv("/tmp/source it's/", '/srv/proofs', "buck's show/101", true);

        try {
            app(RsyncRunner::class)->run($argv);
            $this->fail('Expected RsyncFailedException: the fake ssh always exits 255.');
        } catch (RsyncFailedException $e) {
            $this->assertStringContainsString('exit code 255', $e->getMessage());
        }

        $received = array_values(array_filter(
            explode("\0", (string) file_get_contents($logFile)),
            fn (string $arg) => $arg !== '',
        ));

        $this->assertNotSame([], $received, 'fake ssh was never invoked by rsync');

        // The -e command string was split by rsync, and the key path survived
        // spaces + apostrophes as one argument.
        $iIndex = array_search('-i', $received, true);
        $this->assertNotFalse($iIndex, 'ssh did not receive -i');
        $this->assertSame("/tmp/Certs/it's key dir/my key.pem", $received[$iIndex + 1]);

        // Bounded SSH transport without weakening host-key verification.
        $oIndices = array_keys($received, '-o', true);
        $this->assertGreaterThanOrEqual(2, count($oIndices), 'ssh did not receive both -o options');
        $this->assertSame('ConnectTimeout=15', $received[$oIndices[0] + 1]);
        $this->assertSame('BatchMode=yes', $received[$oIndices[1] + 1]);
        $this->assertStringNotContainsString('StrictHostKeyChecking', implode(' ', $received));

        // Port handling.
        $pIndex = array_search('-p', $received, true);
        $this->assertNotFalse($pIndex, 'ssh did not receive -p');
        $this->assertSame('2222', $received[$pIndex + 1]);

        // rsync expands user@host into ssh's -l user host arguments.
        $lIndex = array_search('-l', $received, true);
        $this->assertNotFalse($lIndex, 'ssh did not receive -l');
        $this->assertSame('forge', $received[$lIndex + 1]);
        $this->assertSame('127.0.0.1', $received[$lIndex + 2]);

        // The remote command's final argument is rsync's own escaping of the
        // destination path — spaces/apostrophes escaped for the remote shell.
        $remoteCommand = end($received);
        $this->assertStringContainsString('rsync --server', implode(' ', $received));
        $this->assertStringContainsString("buck\\'s\\ show/101", (string) $remoteCommand);
        $this->assertStringNotContainsString("buck's show/101", (string) $remoteCommand);

        // The destination arrived at rsync as a single argument.
        $this->assertSame("forge@127.0.0.1:/srv/proofs/buck's show/101", $argv[count($argv) - 1]);
    }
}
