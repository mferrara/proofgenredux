<?php

namespace App\Console\Commands;

use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Models\Show;
use App\Services\ShowWorkSummary;
use Illuminate\Console\Command;

/**
 * The Upload button, from the command line. Proofs always go first: every
 * delivery is queued per kind, so all classes' proofs go up before any web or
 * highres image.
 */
class UploadPendingCommand extends Command
{
    protected $signature = 'proofgen:upload
        {show : Show id}
        {class? : One class; omit with --all for every class that has something to upload}
        {--all : Every class with something to upload}
        {--proofs-only : Queue proofs only (web and highres can follow later)}';

    protected $description = 'Queue uploads for classes with generated photos that are not on the website yet (same as the Upload button)';

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

            $kinds = array_keys(array_filter([
                'proofs' => $row['proofs_to_upload'] > 0,
                'web' => ! $this->option('proofs-only') && $row['web_to_upload'] > 0,
                'highres' => ! $this->option('proofs-only') && $row['highres_to_upload'] > 0,
            ]));

            if ($kinds === []) {
                continue;
            }

            if (! $row['name_ok']) {
                $this->warn($row['class'].': skipped - the website will not accept this class name. '.$row['name_problem']);

                continue;
            }

            DeliverClassOutputs::dispatchByPriority($show->id.'_'.$row['class'], false, $kinds);
            $this->info($row['class'].': queued '.implode(', ', $kinds));
            $queued++;
        }

        $this->line($queued === 0 ? 'Nothing is waiting to be uploaded.' : 'Proofs go first. Watch progress with: php artisan proofgen:status '.$show->id);

        return self::SUCCESS;
    }
}
