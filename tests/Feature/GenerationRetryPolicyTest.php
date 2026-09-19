<?php

use App\Jobs\Photo\GenerateHighresImage;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;

/*
 * Report #11: a single failed proof or highres render buried the job for
 * good, because without a job-level $tries the supervisors' tries=1 applied.
 * The generation jobs now own their retry policy (job-level $tries wins in
 * Laravel's worker, whatever the supervisor says).
 */

it('retries proof and highres generation before giving up', function () {
    expect((new GenerateThumbnails('photo', 'proofs/path'))->tries)->toBe(3)
        ->and((new GenerateThumbnails('photo', 'proofs/path'))->backoff())->toBe([60, 300])
        ->and((new GenerateHighresImage('photo', 'highres/path'))->tries)->toBe(3)
        ->and((new GenerateHighresImage('photo', 'highres/path'))->backoff())->toBe([60, 300]);
});

it('keeps the web image retry policy in place', function () {
    expect((new GenerateWebImage('photo', 'web/path'))->tries)->toBe(3)
        ->and((new GenerateWebImage('photo', 'web/path'))->backoff())->toBe([60, 300]);
});
