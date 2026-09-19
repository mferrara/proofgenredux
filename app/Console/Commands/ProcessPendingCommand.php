<?php

namespace App\Console\Commands;

use App\Models\Show;
use App\Services\ShowSweep;
use Illuminate\Console\Command;

/**
 * The Process button, from the command line: one sweep that queues imports,
 * generation and uploads for a show, then says in plain sentences what it
 * did. Safe to repeat — unique jobs and the busy-delivery check keep a
 * second run from doubling anything.
 */
class ProcessPendingCommand extends Command
{
    protected $signature = 'proofgen:process
        {show : Show id}
        {--class= : One class; omit for every class of the show}
        {--json : Machine-readable output}';

    protected $description = 'Queue everything pending for a show: imports, generation and uploads (same as the Process button)';

    public function handle(ShowSweep $sweep): int
    {
        $show = Show::find($this->argument('show'));
        if (! $show) {
            $this->error('No such show.');

            return self::FAILURE;
        }

        $report = $sweep->sweep($show, $this->option('class'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($report['imports_queued'] as $class => $photos) {
            $this->info($class.': '.$photos.' photos queued for import');
        }
        foreach ($report['deliveries_queued'] as $class => $kinds) {
            $this->info($class.': queued '.implode(', ', $kinds).' for upload');
        }

        $this->line($sweep->message($report));
        $this->line('Watch progress with: php artisan proofgen:status '.$show->id);

        return self::SUCCESS;
    }
}
