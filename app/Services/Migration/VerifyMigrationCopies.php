<?php

namespace App\Services\Migration;

use App\Models\MigrationInventory;
use App\Models\Show;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VerifyMigrationCopies
{
    public function verify(Show $show, bool $thorough = false): array
    {
        $stats = [
            'verified' => 0,
            'failed' => 0,
            'missing_source' => 0,
            'missing_target' => 0,
            'size_mismatch' => 0,
            'sha1_mismatch' => 0,
        ];

        MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->whereIn('status', [
                MigrationInventory::STATUS_COPIED,
                MigrationInventory::STATUS_VERIFIED,
            ])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($thorough, &$stats): void {
                foreach ($rows as $row) {
                    try {
                        $result = $this->verifyRow($row, $thorough);
                        $stats[$result]++;

                        if ($result !== 'verified') {
                            $stats['failed']++;
                        }
                    } catch (Throwable $exception) {
                        $row->markFailed($exception->getMessage());
                        $stats['failed']++;
                    }
                }
            });

        return $stats;
    }

    private function verifyRow(MigrationInventory $row, bool $thorough): string
    {
        if (blank($row->target_disk) || blank($row->target_object_key)) {
            $row->markFailed('missing_target_metadata');

            return 'missing_target';
        }

        $sourceDisk = Storage::disk($row->source_disk);
        $targetDisk = Storage::disk($row->target_disk);

        if (! $sourceDisk->exists($row->source_path)) {
            $row->markFailed('missing_source');

            return 'missing_source';
        }

        if (! $targetDisk->exists($row->target_object_key)) {
            $row->markFailed('missing_target');

            return 'missing_target';
        }

        $sourceSize = $sourceDisk->size($row->source_path);
        $targetSize = $targetDisk->size($row->target_object_key);

        if ($sourceSize !== $targetSize) {
            $row->markFailed('size_mismatch: source='.$sourceSize.' target='.$targetSize);

            return 'size_mismatch';
        }

        $updates = [
            'source_size_bytes' => $sourceSize,
            'status' => MigrationInventory::STATUS_VERIFIED,
            'error_message' => null,
            'last_attempt_at' => now(),
        ];

        if ($thorough) {
            $sourceSha1 = $this->sha1FromDisk($sourceDisk, $row->source_path);
            $targetSha1 = $this->sha1FromDisk($targetDisk, $row->target_object_key);

            if ($sourceSha1 !== $targetSha1) {
                $row->forceFill([
                    'source_sha1' => $sourceSha1,
                    'target_sha1' => $targetSha1,
                ]);
                $row->markFailed('sha1_mismatch: source='.$sourceSha1.' target='.$targetSha1);

                return 'sha1_mismatch';
            }

            $updates['source_sha1'] = $sourceSha1;
            $updates['target_sha1'] = $targetSha1;
        }

        $row->forceFill($updates)->save();

        return 'verified';
    }

    private function sha1FromDisk(FilesystemAdapter $disk, string $path): string
    {
        $stream = $disk->readStream($path);

        if ($stream === false) {
            throw new \RuntimeException('Unable to read '.$path.' for sha1 verification.');
        }

        try {
            $context = hash_init('sha1');

            while (! feof($stream)) {
                hash_update($context, (string) fread($stream, 1024 * 1024));
            }

            return hash_final($context);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
