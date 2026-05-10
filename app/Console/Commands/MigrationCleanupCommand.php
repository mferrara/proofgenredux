<?php

namespace App\Console\Commands;

use App\Models\MigrationInventory;
use App\Models\Show;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrationCleanupCommand extends Command
{
    protected $signature = 'proofgen:migration-cleanup
                            {slug : Ferraraphoto show slug to move into the migration graveyard}
                            {--really : Move verified source files instead of only reporting}';

    protected $description = 'Move verified legacy migration sources into a recoverable graveyard';

    public function handle(): int
    {
        $show = $this->showForSlug((string) $this->argument('slug'));
        if (! $show) {
            $this->error('Show not found for slug '.$this->argument('slug'));

            return Command::FAILURE;
        }

        $rows = MigrationInventory::query()
            ->where('show_slug', $show->ferraraphoto_slug)
            ->where('status', MigrationInventory::STATUS_VERIFIED)
            ->orderBy('id')
            ->get();

        $bytes = (int) $rows->sum('source_size_bytes');
        $this->line(($this->option('really') ? 'Moving' : 'Would move').' '.$rows->count().' verified files ('.number_format($bytes).' bytes).');

        if (! $this->option('really')) {
            $this->line('Run with --really to move files into _migration_graveyard/'.$show->ferraraphoto_slug.'.');

            return Command::SUCCESS;
        }

        $moved = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $disk = Storage::disk($row->source_disk);
            if (! $disk->exists($row->source_path)) {
                $failed++;
                $row->markFailed('cleanup_missing_source');

                continue;
            }

            $target = '_migration_graveyard/'.$row->show_slug.'/'.$row->content_type.'/'.$row->class_number.'/'.basename($row->source_path);
            if (! $disk->move($row->source_path, $target)) {
                $failed++;
                $row->markFailed('cleanup_move_failed');

                continue;
            }

            $moved++;
        }

        $this->table(['Moved', 'Failed'], [[$moved, $failed]]);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showForSlug(string $slug): ?Show
    {
        return Show::query()
            ->where('id', $slug)
            ->orWhere('ferraraphoto_show_slug', $slug)
            ->first();
    }
}
