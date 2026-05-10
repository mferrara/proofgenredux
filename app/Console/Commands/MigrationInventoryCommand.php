<?php

namespace App\Console\Commands;

use App\Services\Migration\MigrationInventoryService;
use Illuminate\Console\Command;

class MigrationInventoryCommand extends Command
{
    protected $signature = 'proofgen:migration-inventory {--show= : Limit inventory to one ferraraphoto show slug}';

    protected $description = 'Inventory legacy ferraraphoto proof, web, and high-res files before cloud migration';

    public function handle(MigrationInventoryService $inventory): int
    {
        $stats = $inventory->inventory($this->option('show') ?: null);

        $this->table(
            ['Discovered', 'Unchanged', 'Missing sources', 'Strays', 'Errors'],
            [[
                $stats['discovered'],
                $stats['unchanged'],
                $stats['missing_sources'],
                $stats['strays'],
                $stats['errors'],
            ]],
        );

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
