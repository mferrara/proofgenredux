<?php

namespace App\Services\Migration;

use App\Models\MigrationInventory;
use App\Models\Show;
use App\Models\StorageProfile;
use App\Services\Storage\StorageProfileResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CopyShowToCloud
{
    public function __construct(
        private readonly MigrationInventoryService $inventory,
        private readonly StorageProfileResolver $profiles,
    ) {}

    public function copy(Show $show, StorageProfile $target): array
    {
        $targetDiskName = $this->profiles->diskFor($target);
        $targetDisk = Storage::disk($targetDiskName);
        $stats = ['copied' => 0, 'skipped' => 0, 'failed' => 0, 'missing_source' => 0];

        MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->whereIn('status', [
                MigrationInventory::STATUS_DISCOVERED,
                MigrationInventory::STATUS_PLANNED,
                MigrationInventory::STATUS_FAILED,
            ])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($targetDiskName, $targetDisk, &$stats): void {
                foreach ($rows as $row) {
                    try {
                        $result = $this->copyRow($row, $targetDiskName, $targetDisk);
                        $stats[$result]++;
                    } catch (Throwable $exception) {
                        $row->markFailed($exception->getMessage());
                        $stats['failed']++;
                    }
                }
            });

        return $stats;
    }

    private function copyRow(MigrationInventory $row, string $targetDiskName, FilesystemAdapter $targetDisk): string
    {
        $sourceDisk = Storage::disk($row->source_disk);

        if (! $sourceDisk->exists($row->source_path)) {
            $row->markFailed('missing_source');

            return 'missing_source';
        }

        $targetKey = $this->inventory->targetKeyFor($row);
        $sourceSize = $sourceDisk->size($row->source_path);

        if ($targetDisk->exists($targetKey)) {
            $targetSize = $targetDisk->size($targetKey);

            if ($targetSize !== $sourceSize) {
                $row->markFailed('target_size_mismatch: source='.$sourceSize.' target='.$targetSize);

                return 'failed';
            }

            $this->markCopied($row, $targetDiskName, $targetKey, $sourceSize);

            return 'skipped';
        }

        $stream = $sourceDisk->readStream($row->source_path);
        if ($stream === false) {
            $row->markFailed('source_read_failed');

            return 'failed';
        }

        try {
            $written = $targetDisk->writeStream($targetKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false || ! $targetDisk->exists($targetKey)) {
            $row->markFailed('target_write_failed');

            return 'failed';
        }

        $targetSize = $targetDisk->size($targetKey);
        if ($targetSize !== $sourceSize) {
            $row->markFailed('target_size_mismatch: source='.$sourceSize.' target='.$targetSize);

            return 'failed';
        }

        $this->markCopied($row, $targetDiskName, $targetKey, $sourceSize);

        return 'copied';
    }

    private function markCopied(MigrationInventory $row, string $targetDiskName, string $targetKey, int $sourceSize): void
    {
        $row->forceFill([
            'target_disk' => $targetDiskName,
            'target_object_key' => $targetKey,
            'source_size_bytes' => $sourceSize,
            'status' => MigrationInventory::STATUS_COPIED,
            'error_message' => null,
            'last_attempt_at' => now(),
        ])->save();
    }
}
