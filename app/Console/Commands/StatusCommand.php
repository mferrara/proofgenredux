<?php

namespace App\Console\Commands;

use App\Models\Show;
use App\Services\AutomaticUploadSettings;
use App\Services\Delivery\DeliveryTargetResolver;
use App\Services\Ferraraphoto\WebsiteShows;
use App\Services\HorizonService;
use App\Services\ShowWorkSummary;
use App\Services\VersionService;
use App\Services\WorkerActivityService;
use App\Services\WorkingFolderHealth;
use Illuminate\Console\Command;

/**
 * "Where do things stand?" for a person or an agent at a show. Read-only.
 * See docs/OPERATING.md.
 */
class StatusCommand extends Command
{
    protected $signature = 'proofgen:status
        {show? : Show id; omit to list the shows}
        {--json : Machine-readable output}
        {--website : Also ask the website whether the show exists there (one request)}';

    protected $description = 'What is running, what is waiting, and the next step for every class of a show (read-only)';

    public function handle(ShowWorkSummary $summary): int
    {
        $activity = app(WorkerActivityService::class)->snapshot();
        $health = [
            'version' => VersionService::getVersion(),
            'workers_running' => app(HorizonService::class)->isRunning(),
            'jobs_waiting' => $activity['waiting'],
            'jobs_running' => $activity['active'],
            'jobs_delayed' => $activity['delayed'],
            'automatic_uploads' => app(AutomaticUploadSettings::class)->enabled(),
            'working_folder' => config('proofgen.fullsize_home_dir'),
            'working_folder_present' => is_dir((string) config('proofgen.fullsize_home_dir')),
            'working_folder_problem' => app(WorkingFolderHealth::class)->problem()['reason'] ?? null,
            'archive_on' => (bool) config('proofgen.archive_enabled'),
            'archive_folder_present' => is_dir((string) config('proofgen.archive_home_dir')),
        ];

        $showId = $this->argument('show');
        $show = $showId ? Show::find($showId) : null;

        if ($showId && ! $show) {
            $this->error('No show "'.$showId.'". Shows: '.Show::orderByDesc('created_at')->limit(10)->pluck('id')->implode(', '));

            return self::FAILURE;
        }

        $payload = ['health' => $health];

        if ($show) {
            $target = app(DeliveryTargetResolver::class)->current($show);
            $payload['show'] = [
                'id' => $show->id,
                'website_slug' => $show->ferraraphoto_slug,
                'on_website' => $this->option('website') ? app(WebsiteShows::class)->check($show->ferraraphoto_slug, true) : null,
                'uploads_go_to' => $target->isFromGallery() ? 'destination from the website' : 'local upload settings',
            ];
            $payload['classes'] = array_map(
                fn (array $row) => $row + ['next_step' => ShowWorkSummary::nextStep($row)],
                $summary->classes($show),
            );
        } else {
            $payload['shows'] = Show::orderByDesc('created_at')->limit(15)->get()
                ->map(fn (Show $s) => ['id' => $s->id, 'classes' => $s->classes()->count(), 'photos' => $s->photos()->count()])->all();
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Version', $health['version']);
        $this->components->twoColumnDetail('Background workers', $health['workers_running'] ? 'running' : '<fg=red>STOPPED - start them from the page header</>');
        $this->components->twoColumnDetail('Jobs', "{$health['jobs_waiting']} waiting, {$health['jobs_running']} running, {$health['jobs_delayed']} delayed");
        $this->components->twoColumnDetail('Automatic uploads', $health['automatic_uploads'] ? 'on' : 'off (upload by hand)');
        $this->components->twoColumnDetail('Working folder', $health['working_folder_present'] ? 'present' : '<fg=red>MISSING: '.$health['working_folder'].'</>');
        if ($health['working_folder_problem']) {
            $this->components->twoColumnDetail('Working folder', '<fg=red>'.$health['working_folder_problem'].'</>');
        }
        $this->components->twoColumnDetail('Archive (backups)', ! $health['archive_on'] ? 'off' : ($health['archive_folder_present'] ? 'on, drive present' : '<fg=red>ON BUT DRIVE MISSING - imports will fail</>'));

        if (! $show) {
            $this->newLine();
            $this->table(['Show', 'Classes', 'Photos'], array_map('array_values', $payload['shows']));
            $this->line('Next: php artisan proofgen:status <show>');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Show', $show->id.' → website "'.$payload['show']['website_slug'].'"'.($payload['show']['on_website'] === false ? ' <fg=red>(NOT on the website)</>' : ''));
        $this->components->twoColumnDetail('Uploads go to', $payload['show']['uploads_go_to']);
        $this->newLine();
        $this->table(
            ['Class', 'To import', 'Imported', 'To generate p/w/h', 'To upload p/w/h', 'Issues', 'Next step'],
            array_map(fn (array $r) => [
                $r['class'].($r['name_ok'] ? '' : ' (!)'), $r['waiting_to_import'], $r['imported'],
                $r['proofs_to_generate'].'/'.$r['web_to_generate'].'/'.$r['highres_to_generate'],
                $r['proofs_to_upload'].'/'.$r['web_to_upload'].'/'.$r['highres_to_upload'],
                $r['open_issues'], $r['next_step'],
            ], $payload['classes']),
        );

        return self::SUCCESS;
    }
}
