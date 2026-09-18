<?php

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Laravel\Horizon\ProvisioningPlan;
use Tests\TestCase;

/**
 * Shipped configuration contract for the dedicated uploads lane.
 *
 * The shipped files are read directly (rather than via config()) so the
 * defaults are asserted even though tests/TestCase.php swaps the uploads
 * driver to sync. Horizon's real ProvisioningPlan is used so the environment
 * merge behaviour is verified without a Redis connection.
 */
class UploadQueueConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploads_connection_keeps_the_derived_budget_and_leaves_the_default_redis_recovery_window_alone(): void
    {
        $queue = require base_path('config/queue.php');
        $proofgen = require base_path('config/proofgen.php');

        $uploads = $queue['connections'][$proofgen['uploads']['connection']];

        $this->assertSame('redis', $uploads['driver']);
        $this->assertSame($proofgen['uploads']['queue'], $uploads['queue']);
        $this->assertSame($proofgen['uploads']['retry_after'], $uploads['retry_after']);

        // one rsync < one upload job < nested derived-upload job < retry_after
        $transfer = $proofgen['sftp']['timeout'];
        $single = $proofgen['uploads']['single_job_timeout'];
        $derived = $proofgen['uploads']['derived_job_timeout'];
        $retryAfter = $proofgen['uploads']['retry_after'];

        $this->assertGreaterThan($transfer, $single);
        $this->assertGreaterThan(3 * $single, $derived);
        $this->assertGreaterThan($derived, $retryAfter);

        // Normal (short) jobs keep their short recovery window.
        $this->assertLessThan($retryAfter, $queue['connections']['redis']['retry_after']);
    }

    public function test_timeout_overrides_use_laravels_environment_repository(): void
    {
        $names = ['SFTP_TRANSFER_TIMEOUT' => '1200', 'UPLOAD_JOB_OVERHEAD' => '120'];
        $saved = [];
        foreach ($names as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            // Laravel loads .env values into its repository; they need not be
            // exported to the OS environment read by getenv().
            putenv($name);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        try {
            $proofgen = require base_path('config/proofgen.php');
            $queue = require base_path('config/queue.php');
            $horizon = require base_path('config/horizon.php');
            $this->assertSame(1200, $proofgen['sftp']['timeout']);
            $this->assertSame(1320, $proofgen['uploads']['single_job_timeout']);
            $this->assertSame(4080, $proofgen['uploads']['derived_job_timeout']);
            $this->assertSame(4320, $queue['connections']['uploads']['retry_after']);
            $this->assertSame(4080, $horizon['defaults']['supervisor-uploads']['timeout']);
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                unset($_ENV[$name], $_SERVER[$name]);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }

    public function test_rsync_runner_reads_the_same_transport_timeout(): void
    {
        $proofgen = require base_path('config/proofgen.php');

        $this->assertSame(
            $proofgen['sftp']['timeout'],
            (int) config('proofgen.sftp.timeout'),
            'The in-app transport timeout must match the shipped derived budget.'
        );
    }

    public function test_proofs_are_taken_first_and_have_a_worker_to_themselves(): void
    {
        $uploads = (require base_path('config/proofgen.php'))['uploads'];
        $plan = new ProvisioningPlan('test-master', config('horizon.environments'), config('horizon.defaults'));

        foreach (['local', 'production'] as $environment) {
            // One worker walks the queues strictly in priority order...
            $general = $plan->optionsFor($environment, 'supervisor-uploads');
            $this->assertSame($uploads['queue'].','.$uploads['web_queue'].','.$uploads['highres_queue'], $general->queue);
            $this->assertFalse($general->balancing(), 'Balancing would give each queue its own worker and lose the priority order.');

            // ...and one takes nothing but proofs, so they never wait behind a highres transfer.
            $proofs = $plan->optionsFor($environment, 'supervisor-uploads-proofs');
            $this->assertNotNull($proofs);
            $this->assertSame($uploads['queue'], $proofs->queue);
            $this->assertSame(1, $proofs->maxProcesses);

            // Card dumps have their own worker and a recovery window longer than the job.
            $cards = $plan->optionsFor($environment, 'supervisor-cards');
            $this->assertSame('cards', $cards->connection);
            $this->assertGreaterThan($cards->timeout, config('queue.connections.cards.retry_after'));
        }
    }

    public function test_horizon_provisions_a_single_upload_worker_in_local_and_production(): void
    {
        $proofgen = require base_path('config/proofgen.php');

        $plan = new ProvisioningPlan('test-master', config('horizon.environments'), config('horizon.defaults'));

        foreach (['local', 'production'] as $environment) {
            $options = $plan->optionsFor($environment, 'supervisor-uploads');

            $this->assertNotNull($options, "supervisor-uploads missing for the {$environment} environment.");
            $this->assertSame($proofgen['uploads']['connection'], $options->connection);
            $this->assertStringStartsWith($proofgen['uploads']['queue'].',', $options->queue);
            $this->assertSame(1, $options->maxProcesses);
            $this->assertSame(1, $options->minProcesses);
            $this->assertSame(5, $options->maxTries);
            $this->assertGreaterThanOrEqual($proofgen['uploads']['derived_job_timeout'], $options->timeout);
            $this->assertGreaterThan($options->timeout, $proofgen['uploads']['retry_after']);
        }
    }

    public function test_test_harness_intercepts_the_uploads_connection_without_redis(): void
    {
        // tests/TestCase.php pins the driver; if this regresses, upload jobs
        // dispatched by feature tests would try to talk to Redis.
        $this->assertSame('sync', config('queue.connections.uploads.driver'));
        $this->assertInstanceOf(SyncQueue::class, app('queue')->connection('uploads'));
    }
}
