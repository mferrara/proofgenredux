<?php

namespace App\Services\Migration;

use App\Models\MigrationInventory;
use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrationInventoryService
{
    /**
     * @return array<string, array{source_disk: string, prefix: string, suffix: string}>
     */
    public function contentTypes(): array
    {
        return [
            MigrationInventory::CONTENT_PROOF_THM => [
                'source_disk' => 'remote_proofs',
                'prefix' => 'proofs',
                'suffix' => (string) (config('proofgen.thumbnails.small.suffix') ?: '_thm').'.jpg',
            ],
            MigrationInventory::CONTENT_PROOF_STD => [
                'source_disk' => 'remote_proofs',
                'prefix' => 'proofs',
                'suffix' => (string) (config('proofgen.thumbnails.large.suffix') ?: '_std').'.jpg',
            ],
            MigrationInventory::CONTENT_WEB_IMAGE => [
                'source_disk' => 'remote_web_images',
                'prefix' => 'web_images',
                'suffix' => (string) (config('proofgen.web_images.suffix') ?: '_web').'.jpg',
            ],
            MigrationInventory::CONTENT_HIGH_RES_IMAGE => [
                'source_disk' => 'remote_highres_images',
                'prefix' => 'highres_images',
                'suffix' => (string) (config('proofgen.highres_images.suffix') ?: '_highres').'.jpg',
            ],
        ];
    }

    public function inventory(?string $showSlug = null): array
    {
        $stats = [
            'discovered' => 0,
            'unchanged' => 0,
            'errors' => 0,
            'missing_sources' => 0,
            'strays' => 0,
        ];

        foreach ($this->contentTypes() as $contentType => $config) {
            $disk = Storage::disk($config['source_disk']);
            $shows = $showSlug ? [$showSlug] : $disk->directories('');

            foreach ($shows as $showPath) {
                $showPath = trim((string) $showPath, '/');

                try {
                    foreach ($disk->directories($showPath) as $classDir) {
                        foreach ($disk->files($classDir) as $file) {
                            if (! str_ends_with(strtolower($file), strtolower($config['suffix']))) {
                                continue;
                            }

                            $row = $this->upsertFile(
                                contentType: $contentType,
                                sourceDisk: $config['source_disk'],
                                sourcePath: $file,
                                sourceSize: $disk->size($file),
                                sourceMtime: Carbon::createFromTimestamp($disk->lastModified($file)),
                                suffix: $config['suffix'],
                            );

                            $row->wasRecentlyCreated ? $stats['discovered']++ : $stats['unchanged']++;
                        }
                    }
                } catch (Throwable) {
                    $stats['errors']++;
                }
            }
        }

        $stats['missing_sources'] = $this->recordMissingSourceIssues($showSlug);
        $stats['strays'] = $this->strayRows($showSlug)->count();

        return $stats;
    }

    public function clearShow(string $showSlug): int
    {
        return MigrationInventory::query()
            ->where('show_slug', $showSlug)
            ->delete();
    }

    public function targetKeyFor(MigrationInventory $row): string
    {
        $config = $this->contentTypes()[$row->content_type] ?? null;

        if ($config === null) {
            throw new \InvalidArgumentException('Unknown content type: '.$row->content_type);
        }

        return $config['prefix'].'/'.$row->show_slug.'/'.$row->class_number.'/'.basename($row->source_path);
    }

    public function showSummary(Show $show): array
    {
        $showSlug = $show->ferraraphoto_slug;
        $rows = MigrationInventory::query()
            ->where('show_slug', $showSlug)
            ->get();

        $byContentType = [];
        foreach (array_keys($this->contentTypes()) as $contentType) {
            $contentRows = $rows->where('content_type', $contentType);
            $byContentType[$contentType] = [
                MigrationInventory::STATUS_DISCOVERED => $contentRows->where('status', MigrationInventory::STATUS_DISCOVERED)->count(),
                MigrationInventory::STATUS_PLANNED => $contentRows->where('status', MigrationInventory::STATUS_PLANNED)->count(),
                MigrationInventory::STATUS_COPIED => $contentRows->where('status', MigrationInventory::STATUS_COPIED)->count(),
                MigrationInventory::STATUS_VERIFIED => $contentRows->where('status', MigrationInventory::STATUS_VERIFIED)->count(),
                MigrationInventory::STATUS_FAILED => $contentRows->where('status', MigrationInventory::STATUS_FAILED)->count(),
                'total' => $contentRows->count(),
            ];
        }

        return [
            'show_slug' => $showSlug,
            'total' => $rows->count(),
            'verified' => $rows->where('status', MigrationInventory::STATUS_VERIFIED)->count(),
            'failed' => $rows->where('status', MigrationInventory::STATUS_FAILED)->count(),
            'by_content_type' => $byContentType,
            'strays' => $this->strayRows($showSlug)->count(),
            'missing_sources' => PhotoIssue::query()
                ->where('issue_type', PhotoIssue::TYPE_MIGRATION_SOURCE_MISSING)
                ->where('show_id', $show->id)
                ->where('status', PhotoIssue::STATUS_OPEN)
                ->count(),
        ];
    }

    public function strayRows(?string $showSlug = null): Collection
    {
        $rows = MigrationInventory::query()
            ->when($showSlug, fn ($query) => $query->where('show_slug', $showSlug))
            ->get();

        return $rows->filter(function (MigrationInventory $row): bool {
            $show = $this->showForSlug($row->show_slug);

            if (! $show) {
                return true;
            }

            $showClass = ShowClass::query()
                ->where('show_id', $show->id)
                ->where('name', $row->class_number)
                ->first();

            if (! $showClass) {
                return true;
            }

            return ! Photo::query()
                ->where('show_class_id', $showClass->id)
                ->where('proof_number', $row->proof_number)
                ->exists();
        })->values();
    }

    public function recordMissingSourceIssues(?string $showSlug = null): int
    {
        $count = 0;
        $shows = $showSlug ? collect([$this->showForSlug($showSlug)])->filter() : Show::query()->get();

        foreach ($shows as $show) {
            $show->loadMissing('classes.photos');

            foreach ($show->classes as $class) {
                foreach ($class->photos as $photo) {
                    $missing = $this->missingContentTypesForPhoto($show, $class, $photo);

                    if ($missing === []) {
                        continue;
                    }

                    $this->upsertMissingSourceIssue($show, $class, $photo, $missing);
                    $count++;
                }
            }
        }

        return $count;
    }

    private function upsertFile(string $contentType, string $sourceDisk, string $sourcePath, int $sourceSize, Carbon $sourceMtime, string $suffix): MigrationInventory
    {
        $parts = explode('/', trim($sourcePath, '/'));

        if (count($parts) < 3) {
            throw new \InvalidArgumentException('Unexpected migration source path: '.$sourcePath);
        }

        $showSlug = $parts[0];
        $classNumber = $parts[1];
        $proofNumber = $this->extractProofNumber(basename($sourcePath), $suffix);

        $row = MigrationInventory::query()->firstOrNew([
            'show_slug' => $showSlug,
            'class_number' => $classNumber,
            'proof_number' => $proofNumber,
            'content_type' => $contentType,
        ]);

        $status = in_array($row->status, [MigrationInventory::STATUS_COPIED, MigrationInventory::STATUS_VERIFIED], true)
            ? $row->status
            : MigrationInventory::STATUS_DISCOVERED;

        $row->fill([
            'source_disk' => $sourceDisk,
            'source_path' => trim($sourcePath, '/'),
            'source_size_bytes' => $sourceSize,
            'source_mtime' => $sourceMtime,
            'status' => $status,
            'error_message' => $status === MigrationInventory::STATUS_DISCOVERED ? null : $row->error_message,
        ])->save();

        return $row;
    }

    private function extractProofNumber(string $filename, string $suffix): string
    {
        return substr($filename, 0, -strlen($suffix));
    }

    private function missingContentTypesForPhoto(Show $show, ShowClass $class, Photo $photo): array
    {
        $expected = [];

        if ($photo->proofs_uploaded_at !== null) {
            $expected[] = MigrationInventory::CONTENT_PROOF_THM;
            $expected[] = MigrationInventory::CONTENT_PROOF_STD;
        }

        if ($photo->web_image_uploaded_at !== null) {
            $expected[] = MigrationInventory::CONTENT_WEB_IMAGE;
        }

        if ($photo->highres_image_uploaded_at !== null) {
            $expected[] = MigrationInventory::CONTENT_HIGH_RES_IMAGE;
        }

        if ($expected === []) {
            return [];
        }

        return array_values(array_filter($expected, fn (string $contentType) => ! MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->where('class_number', $class->name)
            ->where('proof_number', $photo->proof_number)
            ->where('content_type', $contentType)
            ->exists()));
    }

    private function upsertMissingSourceIssue(Show $show, ShowClass $class, Photo $photo, array $missingContentTypes): PhotoIssue
    {
        $payload = [
            'status' => PhotoIssue::STATUS_OPEN,
            'issue_type' => PhotoIssue::TYPE_MIGRATION_SOURCE_MISSING,
            'show_id' => $show->id,
            'show_class_id' => $class->id,
            'existing_photo_id' => $photo->id,
            'existing_proof_number' => $photo->proof_number,
            'existing_sha1' => $photo->sha1,
            'evidence' => [
                'show_slug' => $show->ferraraphoto_slug,
                'class_number' => $class->name,
                'missing_content_types' => $missingContentTypes,
                'note' => 'Photo is marked uploaded locally but one or more legacy source files were not found during migration inventory.',
            ],
        ];

        $existing = PhotoIssue::query()
            ->where('issue_type', PhotoIssue::TYPE_MIGRATION_SOURCE_MISSING)
            ->where('existing_photo_id', $photo->id)
            ->where('status', PhotoIssue::STATUS_OPEN)
            ->first();

        if ($existing) {
            $existing->update($payload);

            return $existing;
        }

        return PhotoIssue::query()->create($payload);
    }

    private function showForSlug(string $showSlug): ?Show
    {
        return Show::query()
            ->where('id', $showSlug)
            ->orWhere('ferraraphoto_show_slug', $showSlug)
            ->first();
    }
}
