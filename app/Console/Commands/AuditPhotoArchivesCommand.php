<?php

namespace App\Console\Commands;

use App\Services\PhotoArchiveService;
use App\Services\PhotoAuditService;
use Illuminate\Console\Command;
use RuntimeException;

class AuditPhotoArchivesCommand extends Command
{
    protected $signature = 'proofgen:audit
                            {--repair : Apply safe repairs (write missing archive copies, refresh stale metadata, backfill photos.sha1)}
                            {--show= : Limit to one show id}
                            {--class= : Limit to one class name; combine with --show for an exact class}
                            {--format=table : Output format: table or json}
                            {--persist-issues=true : Record non-OK findings as photo_issues rows}';

    protected $description = 'Audit photos against on-disk originals + archive copies. Reports SHA mismatches, missing archives, duplicate sha1 rows, duplicate proof numbers, orphan originals, and high-risk records with no original or archive. Optionally applies safe repairs.';

    public function handle(PhotoAuditService $audit): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['table', 'json'], true)) {
            $this->error("Invalid format. Use 'table' or 'json'.");

            return Command::FAILURE;
        }

        try {
            app(PhotoArchiveService::class)->assertConfiguredRootAvailable();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $result = $audit->auditAll(
            showFilter: $this->option('show') ?: null,
            classFilter: $this->option('class') ?: null,
            repair: (bool) $this->option('repair'),
        );

        if ($format === 'json') {
            $this->output->write(json_encode($result, JSON_PRETTY_PRINT));
            $this->newLine();
        } else {
            $this->renderTable($result);
        }

        $stats = $result['stats'];
        $hasProblems = $stats['photos_needs_attention'] > 0
            || $stats['duplicate_sha1_groups'] > 0
            || $stats['duplicate_proof_number_groups'] > 0
            || $stats['orphan_originals'] > 0
            || $stats['photos_without_original_and_archive'] > 0
            || $stats['ingest_stragglers'] > 0
            || $stats['orphan_quarantine_files'] > 0;

        return $hasProblems ? Command::FAILURE : Command::SUCCESS;
    }

    private function renderTable(array $result): void
    {
        $stats = $result['stats'];

        $photoFindings = array_filter($result['findings'], fn ($f) => ($f['kind'] ?? null) === 'photo');
        if (! empty($photoFindings)) {
            $this->table(
                ['Photo', 'Status', 'Source', 'Archive', 'Repaired', 'Note'],
                array_map(fn ($f) => [
                    $f['photo_id'],
                    $f['status'],
                    $f['source_exists'] ? 'yes' : 'no',
                    $f['archive_exists'] ? 'yes' : 'no',
                    $f['repaired'] ? 'yes' : 'no',
                    $f['repair_note'] ?? '',
                ], $photoFindings)
            );
        }

        $this->info(sprintf(
            'Photos: %d total, %d ok, %d repaired, %d needs attention.',
            $stats['photos_total'],
            $stats['photos_ok'],
            $stats['photos_repaired'],
            $stats['photos_needs_attention'],
        ));

        if ($stats['duplicate_sha1_groups'] > 0) {
            $this->warn(sprintf('Duplicate SHA-1 groups: %d', $stats['duplicate_sha1_groups']));
        }
        if ($stats['duplicate_proof_number_groups'] > 0) {
            $this->warn(sprintf('Duplicate proof number groups: %d', $stats['duplicate_proof_number_groups']));
        }
        if ($stats['orphan_originals'] > 0) {
            $this->warn(sprintf('Orphan originals (file present, no Photo row): %d', $stats['orphan_originals']));
        }
        if ($stats['photos_without_original_and_archive'] > 0) {
            $this->warn(sprintf('Photos missing both original AND archive: %d', $stats['photos_without_original_and_archive']));
        }
        if ($stats['ingest_stragglers'] > 0) {
            $this->warn(sprintf('Non-image files in ingest folders (stragglers): %d', $stats['ingest_stragglers']));
        }
        if ($stats['orphan_quarantine_files'] > 0) {
            $this->warn(sprintf('Quarantined files without matching open issues: %d', $stats['orphan_quarantine_files']));
        }
    }
}
