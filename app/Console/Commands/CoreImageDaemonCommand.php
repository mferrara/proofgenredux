<?php

namespace App\Console\Commands;

use App\Services\CoreImageDaemonService;
use Illuminate\Console\Command;

class CoreImageDaemonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'coreimage:daemon {action : start|stop|status|restart}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage the Core Image enhancement daemon';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');
        $service = app(CoreImageDaemonService::class);

        switch ($action) {
            case 'start':
                $this->info('Starting Core Image daemon...');
                if ($service->startDaemon()) {
                    sleep(2); // Wait for daemon to start
                    if ($service->isCoreImageAvailable()) {
                        $this->info('Core Image daemon started successfully');

                        return Command::SUCCESS;
                    }
                }
                $this->error('Failed to start Core Image daemon');

                return Command::FAILURE;

            case 'stop':
                $this->info('Stopping Core Image daemon...');
                if ($service->stopDaemon()) {
                    $this->info('Core Image daemon stopped');
                } else {
                    $this->warn('Core Image daemon was not running');
                }

                return Command::SUCCESS;

            case 'status':
                if ($service->isCoreImageAvailable()) {
                    $this->info('Core Image daemon is running');

                    $pidFile = storage_path('core-image-daemon.pid');
                    if (file_exists($pidFile)) {
                        $pid = trim(file_get_contents($pidFile));
                        $this->info("PID: $pid");
                    }
                } else {
                    $this->warn('Core Image daemon is not running');
                }

                return Command::SUCCESS;

            case 'restart':
                $this->info('Restarting Core Image daemon...');
                $service->stopDaemon();
                sleep(1);
                if ($service->startDaemon()) {
                    sleep(2); // Wait for daemon to start
                    if ($service->isCoreImageAvailable()) {
                        $this->info('Core Image daemon restarted successfully');

                        return Command::SUCCESS;
                    }
                }
                $this->error('Failed to restart Core Image daemon');

                return Command::FAILURE;

            default:
                $this->error("Unknown action: $action");
                $this->info('Available actions: start, stop, status, restart');

                return Command::FAILURE;
        }
    }
}
