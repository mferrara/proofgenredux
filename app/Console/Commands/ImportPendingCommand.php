<?php

namespace App\Console\Commands;

use App\Jobs\ShowClass\ImportClassPhotos;
use App\Models\Show;
use App\Services\ShowWorkSummary;
use Illuminate\Console\Command;

/**
 * The Import button, from the command line: queue the import of photos that
 * are sitting in class folders. Never touches a folder whose name the website
 * would reject, and never re-imports anything (the importer skips known photos).
 */
class ImportPendingCommand extends Command
{
    protected $signature = 'proofgen:import
        {show : Show id}
        {class? : One class folder; omit with --all for every class that has photos waiting}
        {--all : Every class with photos waiting}';

    protected $description = 'Queue the import of photos waiting in class folders (same as the Import button)';

    public function handle(ShowWorkSummary $summary): int
    {
        $show = Show::find($this->argument('show'));
        if (! $show) {
            $this->error('No such show.');

            return self::FAILURE;
        }

        if (! $this->argument('class') && ! $this->option('all')) {
            $this->error('Name a class, or pass --all.');

            return self::FAILURE;
        }

        $queued = 0;
        foreach ($summary->classes($show) as $row) {
            if ($this->argument('class') && $row['class'] !== $this->argument('class')) {
                continue;
            }

            if (! $row['name_ok']) {
                $this->warn($row['class'].': skipped - '.$row['name_problem']);

                continue;
            }

            if ($row['waiting_to_import'] === 0) {
                continue;
            }

            ImportClassPhotos::dispatch($show->id, $row['class'])->onQueue('imports');
            $this->info($row['class'].': '.$row['waiting_to_import'].' photos queued for import');
            $queued++;
        }

        $this->line($queued === 0 ? 'Nothing is waiting to be imported.' : 'Watch progress with: php artisan proofgen:status '.$show->id);

        return self::SUCCESS;
    }
}
