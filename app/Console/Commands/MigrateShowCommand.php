<?php

namespace App\Console\Commands;

use App\Models\MigrationInventory;
use App\Models\Show;
use App\Models\StorageProfile;
use App\Services\Migration\CopyShowToCloud;
use App\Services\Migration\MigrationInventoryService;
use App\Services\Migration\RcloneShowCopyCommandBuilder;
use App\Services\Migration\RcloneStatsParser;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MigrateShowCommand extends Command
{
    protected $signature = 'proofgen:migrate-show
                            {slug : Ferraraphoto show slug to copy}
                            {--rclone-remote= : rclone remote configured on the ferraraphoto host}
                            {--bucket= : Cloud bucket name}
                            {--ssh-host= : ferraraphoto SSH host}
                            {--ssh-user= : ferraraphoto SSH user}
                            {--dry-run : Print commands without copying}
                            {--storage-walk : Copy through Laravel Storage instead of remote rclone}';

    protected $description = 'Copy one legacy show to the active cloud storage profile';

    public function handle(
        CopyShowToCloud $storageCopy,
        MigrationInventoryService $inventory,
        RcloneShowCopyCommandBuilder $commands,
        RcloneStatsParser $parser,
        StorageProfileResolver $profiles,
    ): int {
        $show = $this->showForSlug((string) $this->argument('slug'));
        if (! $show) {
            $this->error('Show not found for slug '.$this->argument('slug'));

            return Command::FAILURE;
        }

        $activeProfile = $this->activeCloudProfile();
        if (! $activeProfile) {
            $this->error('No active writable cloud storage profile found.');

            return Command::FAILURE;
        }

        if ($this->option('storage-walk')) {
            $stats = $storageCopy->copy($show, $activeProfile);
            $this->table(['Copied', 'Skipped', 'Failed', 'Missing source'], [[
                $stats['copied'],
                $stats['skipped'],
                $stats['failed'],
                $stats['missing_source'],
            ]]);

            return ($stats['failed'] + $stats['missing_source']) > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        foreach (['rclone-remote', 'bucket', 'ssh-host', 'ssh-user'] as $option) {
            if (blank($this->option($option))) {
                $this->error('--'.$option.' is required unless --storage-walk is used.');

                return Command::FAILURE;
            }
        }

        $copyCommands = $commands->commands(
            showSlug: $show->ferraraphoto_slug,
            rcloneRemote: (string) $this->option('rclone-remote'),
            bucket: (string) $this->option('bucket'),
            sshHost: (string) $this->option('ssh-host'),
            sshUser: (string) $this->option('ssh-user'),
            dryRun: (bool) $this->option('dry-run'),
        );

        if ($this->option('dry-run')) {
            foreach ($copyCommands as $label => $command) {
                $this->line($label.': '.$command);
            }

            return Command::SUCCESS;
        }

        $targetDisk = $profiles->diskFor($activeProfile);
        foreach ($copyCommands as $label => $command) {
            $this->info('Copying '.$label.'...');
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(null);
            $process->run();

            $parsed = $parser->parse($process->getOutput()."\n".$process->getErrorOutput(), $process->getExitCode() ?? 1);
            if (! $parsed['ok']) {
                $this->error('rclone failed for '.$label.' (errors='.$parsed['errors'].')');
                $this->markRowsFailed($show, $label, 'rclone_failed');

                return Command::FAILURE;
            }

            $this->markRowsCopied($show, $label, $targetDisk, $inventory);
        }

        return Command::SUCCESS;
    }

    private function markRowsCopied(Show $show, string $label, string $targetDisk, MigrationInventoryService $inventory): void
    {
        $contentTypes = $this->contentTypesForLabel($label);

        MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->whereIn('content_type', $contentTypes)
            ->whereIn('status', [
                MigrationInventory::STATUS_DISCOVERED,
                MigrationInventory::STATUS_PLANNED,
                MigrationInventory::STATUS_FAILED,
            ])
            ->get()
            ->each(function (MigrationInventory $row) use ($targetDisk, $inventory): void {
                $row->forceFill([
                    'target_disk' => $targetDisk,
                    'target_object_key' => $inventory->targetKeyFor($row),
                    'status' => MigrationInventory::STATUS_COPIED,
                    'error_message' => null,
                    'last_attempt_at' => now(),
                ])->save();
            });
    }

    private function markRowsFailed(Show $show, string $label, string $message): void
    {
        MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->whereIn('content_type', $this->contentTypesForLabel($label))
            ->get()
            ->each(fn (MigrationInventory $row) => $row->markFailed($message));
    }

    /**
     * @return list<string>
     */
    private function contentTypesForLabel(string $label): array
    {
        return match ($label) {
            'proofs' => [
                MigrationInventory::CONTENT_PROOF_THM,
                MigrationInventory::CONTENT_PROOF_STD,
            ],
            'web_images' => [MigrationInventory::CONTENT_WEB_IMAGE],
            'highres_images' => [MigrationInventory::CONTENT_HIGH_RES_IMAGE],
            default => [],
        };
    }

    private function activeCloudProfile(): ?StorageProfile
    {
        return StorageProfile::query()
            ->where('is_active', true)
            ->where('is_writable', true)
            ->where('id', '!=', StorageProfile::LEGACY_LOCAL_ID)
            ->first();
    }

    private function showForSlug(string $slug): ?Show
    {
        return Show::query()
            ->where('id', $slug)
            ->orWhere('ferraraphoto_show_slug', $slug)
            ->first();
    }
}
