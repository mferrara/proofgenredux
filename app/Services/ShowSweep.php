<?php

namespace App\Services;

use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Services\Delivery\DeliveryTargetResolver;
use App\Services\Ferraraphoto\WebsiteShows;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One idempotent pass over a show: queue the import of photos waiting in
 * class folders, queue generation for imported photos that lack outputs, and
 * queue delivery of generated photos that are not on the website yet. This is
 * the machinery behind the show page's Process button and proofgen:process.
 *
 * It never throws for "cannot" — it reports the problem in the returned
 * report instead. Re-running it never doubles the work: import and generation
 * jobs are unique per class/photo, and a class that already has a delivery
 * queued, running or delayed is left alone.
 */
final class ShowSweep
{
    /**
     * One idempotent pass over a show. Never throws for "cannot"; it reports it.
     *
     * @return array{imports_queued: array<string, int>, generation_queued: array{proofs: int, web: int, highres: int}, deliveries_queued: array<string, list<string>>, skipped_folders: array<string, string>, open_issues: int, missing_originals: int, failed_jobs: int, notes: list<string>}
     */
    public function sweep(Show $show, ?string $onlyClass = null): array
    {
        $report = [
            'imports_queued' => [],
            'generation_queued' => ['proofs' => 0, 'web' => 0, 'highres' => 0],
            'deliveries_queued' => [],
            'skipped_folders' => [],
            'open_issues' => 0,
            'missing_originals' => 0,
            'failed_jobs' => 0,
            'notes' => [],
        ];

        $rows = app(ShowWorkSummary::class)->classes($show);
        if ($onlyClass !== null) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['class'] === $onlyClass));
        }

        $this->queueImports($show, $rows, $report);
        $this->queueGeneration($show, $rows, $report);
        $this->queueDeliveries($show, $rows, $report);

        $report['open_issues'] = PhotoIssue::open()->where('show_id', $show->id)->count();
        // Only recent failures: an install keeps every failed job it ever had,
        // and an old count would turn every summary into a warning.
        $report['failed_jobs'] = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return $report;
    }

    /**
     * One plain sentence (or two) for the operator, from a report. Shared by
     * button and command. Omits the empty parts; "Nothing is pending." when
     * there is nothing at all to say.
     */
    public function message(array $report): string
    {
        $parts = [];
        if (($queued = $this->queuedSentence($report)) !== null) {
            $parts[] = $queued;
        }

        foreach ($report['skipped_folders'] ?? [] as $folder => $problem) {
            $parts[] = "Skipped '{$folder}': {$problem}.";
        }

        $issues = (int) ($report['open_issues'] ?? 0);
        if ($issues === 1) {
            $parts[] = '1 photo needs review on the Issues page.';
        } elseif ($issues > 0) {
            $parts[] = "{$issues} photos need review on the Issues page.";
        }

        $missing = (int) ($report['missing_originals'] ?? 0);
        if ($missing > 0) {
            $parts[] = $missing.' '.str('photo')->plural($missing).' cannot be generated because the original is not on this disk.';
        }

        $failed = (int) ($report['failed_jobs'] ?? 0);
        if ($failed > 0) {
            $parts[] = $failed.' background '.str('job')->plural($failed).' failed in the last day.';
        }

        foreach ($report['notes'] ?? [] as $note) {
            $parts[] = $note;
        }

        return $parts === [] ? 'Nothing is pending.' : implode(' ', $parts);
    }

    /**
     * A few words for the small badge in the show page header; the toast
     * carries the full message.
     */
    public function shortMessage(array $report): string
    {
        $queued = $this->queuedSentence($report) !== null;
        $attention = ($report['skipped_folders'] ?? []) !== [] || ($report['notes'] ?? []) !== [];

        return match (true) {
            $queued && $attention => 'Work queued; see the message.',
            $queued => 'Work queued.',
            $attention => 'Nothing queued; see the message.',
            default => 'Nothing is pending.',
        };
    }

    private function queueImports(Show $show, array $rows, array &$report): void
    {
        // Same check as proofgen:status: with Backups on but the drive absent,
        // every import would fail, so do not queue any. Backups are never
        // turned off here.
        $archiveMissing = config('proofgen.archive_enabled') && ! is_dir((string) config('proofgen.archive_home_dir'));
        if ($archiveMissing) {
            $report['notes'][] = 'Imports skipped: the archive drive is not plugged in.';
        }

        foreach ($rows as $row) {
            if ($row['waiting_to_import'] === 0) {
                continue;
            }

            if (! $row['name_ok']) {
                $report['skipped_folders'][$row['class']] = $row['name_problem'];

                continue;
            }

            if ($archiveMissing) {
                continue;
            }

            // Exactly what the Import button queues; the job is unique per
            // show/class, so a batch already queued or running is not doubled.
            ImportClassPhotos::dispatch($show->id, $row['class'])->onQueue('imports');
            $report['imports_queued'][$row['class']] = $row['waiting_to_import'];
        }
    }

    private function queueGeneration(Show $show, array $rows, array &$report): void
    {
        $classes = $show->classes->keyBy('name');
        foreach ($rows as $row) {
            $class = $classes->get($row['class']);
            if ($class === null) {
                continue;
            }

            // The pending* methods already honour the web/highres switches and
            // skip photos whose original is not on disk; the jobs are unique
            // per photo, so one already queued or running is not doubled.
            $pendingProofs = $class->photosNotProofed()->count();
            $queuedProofs = $class->proofPendingPhotos();
            $report['generation_queued']['proofs'] += $queuedProofs;
            $report['generation_queued']['web'] += $class->webImagePendingPhotos();
            $report['generation_queued']['highres'] += $class->highresImagePendingPhotos();

            // Proofs that could not be queued because the original is gone.
            // Nothing can be generated for them until the audit/repair puts
            // the original back.
            $report['missing_originals'] += max($pendingProofs - $queuedProofs, 0);
        }
    }

    private function queueDeliveries(Show $show, array $rows, array &$report): void
    {
        // A destination the website reported after files already went up
        // somewhere else needs the operator's OK; never upload against it.
        if (app(DeliveryTargetResolver::class)->pending($show) !== null) {
            $report['notes'][] = "Uploads skipped: the website's delivery destination changed. Review it on this page.";

            return;
        }

        // Known-missing on the website means every upload would fail. An
        // unknown answer (older website, or it did not reply) proceeds as
        // today. Read from the cache only; no HTTP call in a sweep.
        if (app(WebsiteShows::class)->exists($show->ferraraphoto_slug) === false) {
            $report['notes'][] = 'Uploads skipped: create this show on the website first.';

            return;
        }

        try {
            $busy = app(QueuedWorkStatus::class)->busyDeliveries($show->id);
        } catch (Throwable) {
            $report['notes'][] = 'Uploads skipped: the queue could not be read to check which classes are already uploading.';

            return;
        }

        $classes = $show->classes->keyBy('name');
        foreach ($rows as $row) {
            $class = $classes->get($row['class']);
            if ($class === null || isset($busy[$row['class']])) {
                continue;
            }

            $kinds = array_keys(array_filter([
                'proofs' => $class->photosProofedNotUploaded()->exists(),
                'web' => $class->photosWebImagedNotUploaded()->exists(),
                'highres' => $class->photosHighresImagedNotUploaded()->exists(),
            ]));
            if ($kinds === []) {
                continue;
            }

            // Same per-kind delivery the Upload button queues, so proofs of
            // every class still go up before any web or highres image.
            DeliverClassOutputs::dispatchByPriority($class->id, false, $kinds);
            $report['deliveries_queued'][$row['class']] = $kinds;
        }
    }

    /**
     * The "Queued: ..." sentence, or null when the sweep queued nothing.
     */
    private function queuedSentence(array $report): ?string
    {
        $chunks = [];

        $imports = $report['imports_queued'] ?? [];
        if ($imports !== []) {
            $importClasses = count($imports);
            $importPhotos = array_sum($imports);
            $chunks[] = $importClasses.' '.str('class')->plural($importClasses)
                .' importing ('.$importPhotos.' '.str('photo')->plural($importPhotos).')';
        }

        $generating = [];
        foreach (['proofs' => 'proof', 'web' => 'web image', 'highres' => 'high-res image'] as $kind => $noun) {
            $count = (int) ($report['generation_queued'][$kind] ?? 0);
            if ($count > 0) {
                $generating[] = $count.' '.str($noun)->plural($count);
            }
        }
        if ($generating !== []) {
            $chunks[] = $this->joinList($generating).' generating';
        }

        $deliveries = $report['deliveries_queued'] ?? [];
        if ($deliveries !== []) {
            $uploading = count($deliveries);
            $chunks[] = $uploading.' '.str('class')->plural($uploading).' uploading';
        }

        if ($chunks === []) {
            return null;
        }

        return 'Queued: '.implode(', ', $chunks).'.';
    }

    /**
     * "5 proofs", or "5 proofs, 2 web images and 1 high-res image".
     *
     * @param  list<string>  $items
     */
    private function joinList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
