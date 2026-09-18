<?php

namespace App\Services\Feedback;

use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\HorizonService;
use App\Services\VersionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The facts a report needs so nobody has to ask for them: what version is
 * running, whether the workers and drives are up, what failed recently.
 * Read-only. Every probe is independent; one that fails reports its error
 * instead of sinking the report.
 */
class DiagnosticsCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(int $logLines = 40): array
    {
        return [
            'app' => $this->probe(fn () => [
                'version' => VersionService::getVersion(),
                'commit' => trim(Process::path(base_path())->run('git rev-parse --short HEAD')->output()) ?: null,
                'modified_tracked_files' => $this->modifiedTrackedFiles(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'os' => php_uname('s').' '.php_uname('r').' '.php_uname('m'),
                'environment' => app()->environment(),
            ]),
            'database' => $this->probe(fn () => [
                'driver' => DB::getDriverName(),
                'pending_migrations' => $this->pendingMigrations(),
                'shows' => Show::count(),
                'classes' => ShowClass::count(),
                'photos' => Photo::count(),
                'open_photo_issues' => PhotoIssue::open()->count(),
            ]),
            'workers' => $this->probe(fn () => [
                'horizon_running' => app(HorizonService::class)->isRunning(),
                'queue_connection' => config('queue.default'),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'recent_failures' => $this->recentFailures(),
            ]),
            'storage' => $this->probe(fn () => [
                'working_folder_present' => is_dir((string) config('proofgen.fullsize_home_dir')),
                'archive_enabled' => (bool) config('proofgen.archive_enabled'),
                'archive_folder_present' => is_dir((string) config('proofgen.archive_home_dir')),
            ]),
            'delivery' => $this->probe(fn () => [
                'driver' => config('proofgen.sftp.driver'),
                'website' => config('proofgen.ferraraphoto.base_url'),
                'api_token_set' => filled(config('proofgen.ferraraphoto.api_token')),
                'sftp_host_set' => filled(config('proofgen.sftp.host')),
                'private_key_file_present' => is_file((string) config('proofgen.sftp.private_key')),
                'automatic_uploads' => (bool) config('proofgen.upload_proofs'),
            ]),
            'log_tail' => $this->probe(fn () => $this->logTail($logLines)),
        ];
    }

    private function probe(callable $probe): mixed
    {
        try {
            return $probe();
        } catch (Throwable $exception) {
            return ['unavailable' => class_basename($exception).': '.$exception->getMessage()];
        }
    }

    /**
     * @return array<int, string>
     */
    private function modifiedTrackedFiles(): array
    {
        $output = Process::path(base_path())->run('git status --porcelain --untracked-files=no')->output();

        return array_slice(array_values(array_filter(array_map('trim', explode("\n", $output)))), 0, 20);
    }

    /**
     * @return array<int, string>
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));

        return array_values(array_diff($files, $migrator->getRepository()->getRan()));
    }

    /**
     * @return array<int, string>
     */
    private function recentFailures(): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(3)
            ->get(['failed_at', 'exception'])
            ->map(fn ($row) => $row->failed_at.' '.mb_strimwidth(strtok((string) $row->exception, "\n") ?: '', 0, 300, '…'))
            ->all();
    }

    /**
     * The end of the application log without stack-trace frames, which are
     * long, repetitive, and full of local paths.
     *
     * @return array<int, string>
     */
    private function logTail(int $lines): array
    {
        $path = storage_path('logs/laravel.log');

        if ($lines <= 0 || ! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        $size = filesize($path) ?: 0;
        fseek($handle, max(0, $size - 200_000));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        $kept = array_values(array_filter(
            explode("\n", $chunk),
            fn (string $line) => trim($line) !== '' && ! preg_match('/^(#\d+ |\[stacktrace\]|"\}?$)/', ltrim($line)),
        ));

        return array_map(
            fn (string $line) => mb_strimwidth($line, 0, 400, '…'),
            array_slice($kept, -$lines),
        );
    }
}
