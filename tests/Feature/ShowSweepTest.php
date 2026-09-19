<?php

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\GenerateWebImage;
use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Models\Photo;
use App\Models\PhotoIssue;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\Delivery\DeliveryTarget;
use App\Services\Delivery\DeliveryTargetResolver;
use App\Services\Ferraraphoto\WebsiteShows;
use App\Services\QueuedWorkStatus;
use App\Services\ShowSweep;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * The Process sweep (ShowSweep): one idempotent pass that queues imports,
 * generation and deliveries, and reports what it could not do instead of
 * throwing. This is the Process button and proofgen:process.
 */

beforeEach(function () {
    config(['testing.skip_file_operations' => true]);
    Storage::fake('fullsize');
    Storage::fake('archive');
    config([
        'proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path(''),
        'proofgen.archive_home_dir' => Storage::disk('archive')->path(''),
        'proofgen.archive_enabled' => false,
    ]);
    Bus::fake();
    Show::withoutEvents(fn () => Show::create(['id' => '26AAC', 'name' => '26AAC']));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => '26AAC_005', 'show_id' => '26AAC', 'name' => '005']));
    Storage::disk('fullsize')->makeDirectory('26AAC/005');
});

function proofedNotUploadedPhoto(): Photo
{
    return Photo::withoutEvents(fn () => Photo::create([
        'id' => '26AAC_005_26AAC_00001', 'show_class_id' => '26AAC_005',
        'proof_number' => '26AAC_00001', 'file_type' => 'jpg', 'sha1' => sha1('proofed'),
        'proofs_generated_at' => now(),
    ]));
}

it('queues imports for photos waiting in a valid class folder', function () {
    Storage::disk('fullsize')->put('26AAC/005/_Z5A0001.JPG', 'x');
    Storage::disk('fullsize')->put('26AAC/005/_Z5A0002.JPG', 'y');

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['imports_queued'])->toBe(['005' => 2]);
    Bus::assertDispatched(ImportClassPhotos::class, 1);
    Bus::assertDispatched(fn (ImportClassPhotos $job) => $job->show_id === '26AAC'
        && $job->class === '005'
        && $job->queue === 'imports');
});

it('lists a folder the website would reject as skipped and imports nothing from it', function () {
    Storage::disk('fullsize')->put('26AAC/Halter Class/_Z5A0003.JPG', 'z');

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['imports_queued'])->toBe([])
        ->and($report['skipped_folders']['Halter Class'])->toContain('Suggested:');
    Bus::assertNothingDispatched();
});

it('queues proof generation for an imported photo whose original is on disk', function () {
    $photo = Photo::withoutEvents(fn () => Photo::create([
        'id' => '26AAC_005_26AAC_00001', 'show_class_id' => '26AAC_005',
        'proof_number' => '26AAC_00001', 'file_type' => 'jpg', 'sha1' => sha1('a'),
    ]));
    Storage::disk('fullsize')->put('26AAC/005/originals/26AAC_00001.jpg', 'x');

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['generation_queued']['proofs'])->toBe(1)
        ->and($report['missing_originals'])->toBe(0);
    Bus::assertDispatched(GenerateThumbnails::class, fn ($job) => $job->photo_id === $photo->id);
});

it('counts photos without an original on disk as missing and queues nothing for them', function () {
    Photo::withoutEvents(fn () => Photo::create([
        'id' => '26AAC_005_26AAC_00002', 'show_class_id' => '26AAC_005',
        'proof_number' => '26AAC_00002', 'file_type' => 'jpg', 'sha1' => sha1('b'),
    ]));

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['generation_queued'])->toBe(['proofs' => 0, 'web' => 0, 'highres' => 0])
        ->and($report['missing_originals'])->toBe(1);
    Bus::assertNothingDispatched();
});

it('queues delivery of generated-but-not-uploaded proofs', function () {
    proofedNotUploadedPhoto();

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['deliveries_queued'])->toBe(['005' => ['proofs']]);
    Bus::assertDispatched(DeliverClassOutputs::class, 1);
    Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->classId === '26AAC_005'
        && $job->automatic === false
        && $job->kinds === ['proofs']);
});

it('leaves a class alone that already has a delivery queued', function () {
    proofedNotUploadedPhoto();
    $status = Mockery::mock(QueuedWorkStatus::class);
    $status->shouldReceive('busyDeliveries')->with('26AAC')->andReturn(['005' => true]);
    app()->instance(QueuedWorkStatus::class, $status);

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['deliveries_queued'])->toBe([]);
    Bus::assertNotDispatched(DeliverClassOutputs::class);
});

it('reports uploads as skipped when the queue cannot be read', function () {
    proofedNotUploadedPhoto();
    $status = Mockery::mock(QueuedWorkStatus::class);
    $status->shouldReceive('busyDeliveries')->andThrow(new RuntimeException('Redis unavailable'));
    app()->instance(QueuedWorkStatus::class, $status);

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['deliveries_queued'])->toBe([])
        ->and($report['notes'][0])->toContain('queue could not be read');
    Bus::assertNotDispatched(DeliverClassOutputs::class);
});

it('skips uploads while a changed delivery destination is waiting to be accepted', function () {
    proofedNotUploadedPhoto();
    $resolver = Mockery::mock(DeliveryTargetResolver::class);
    $resolver->shouldReceive('pending')->andReturn(DeliveryTarget::fromArray([
        'source' => 'gallery', 'driver' => 'rsync_ssh', 'host' => 'other-server',
        'directories' => ['proofs' => '/srv/26AAC/proofs'], 'show_slug' => '26AAC',
    ]));
    app()->instance(DeliveryTargetResolver::class, $resolver);

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['deliveries_queued'])->toBe([])
        ->and($report['notes'])->toContain("Uploads skipped: the website's delivery destination changed. Review it on this page.");
    Bus::assertNotDispatched(DeliverClassOutputs::class);
});

it('skips uploads when the show does not exist on the website', function () {
    proofedNotUploadedPhoto();
    $shows = Mockery::mock(WebsiteShows::class);
    $shows->shouldReceive('exists')->andReturn(false);
    app()->instance(WebsiteShows::class, $shows);

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['deliveries_queued'])->toBe([])
        ->and($report['notes'])->toContain('Uploads skipped: create this show on the website first.');
    Bus::assertNotDispatched(DeliverClassOutputs::class);
});

it('skips imports when backups are on but the archive drive is missing, and still generates', function () {
    config([
        'proofgen.archive_enabled' => true,
        'proofgen.archive_home_dir' => '/nonexistent-proofgen-archive',
    ]);
    Photo::withoutEvents(fn () => Photo::create([
        'id' => '26AAC_005_26AAC_00001', 'show_class_id' => '26AAC_005',
        'proof_number' => '26AAC_00001', 'file_type' => 'jpg', 'sha1' => sha1('a'),
    ]));
    Storage::disk('fullsize')->put('26AAC/005/originals/26AAC_00001.jpg', 'x');
    Storage::disk('fullsize')->put('26AAC/005/_Z5A0009.JPG', 'raw');

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['imports_queued'])->toBe([])
        ->and($report['notes'])->toContain('Imports skipped: the archive drive is not plugged in.')
        ->and($report['generation_queued']['proofs'])->toBe(1);
    Bus::assertNotDispatched(ImportClassPhotos::class);
    Bus::assertDispatched(GenerateThumbnails::class);
});

it('honours the web-generation switch', function () {
    config(['proofgen.generate_web_images.enabled' => false]);
    Photo::withoutEvents(fn () => Photo::create([
        'id' => '26AAC_005_26AAC_00001', 'show_class_id' => '26AAC_005',
        'proof_number' => '26AAC_00001', 'file_type' => 'jpg', 'sha1' => sha1('a'),
    ]));
    Storage::disk('fullsize')->put('26AAC/005/originals/26AAC_00001.jpg', 'x');

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['generation_queued']['web'])->toBe(0)
        ->and($report['generation_queued']['proofs'])->toBe(1);
    Bus::assertNotDispatched(GenerateWebImage::class);
    Bus::assertDispatched(GenerateThumbnails::class);
});

it('calls an empty show nothing pending', function () {
    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['imports_queued'])->toBe([])
        ->and($report['open_issues'])->toBe(0)
        ->and($report['failed_jobs'])->toBe(0)
        ->and($report['notes'])->toBe([]);
    expect(app(ShowSweep::class)->message($report))->toBe('Nothing is pending.');
});

it('can limit the sweep to one class', function () {
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => '26AAC_010', 'show_id' => '26AAC', 'name' => '010']));
    Storage::disk('fullsize')->makeDirectory('26AAC/010');
    Storage::disk('fullsize')->put('26AAC/005/_Z5A0001.JPG', 'x');
    Storage::disk('fullsize')->put('26AAC/010/_Z5A0002.JPG', 'y');

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'), '010');

    expect($report['imports_queued'])->toBe(['010' => 1]);
    Bus::assertDispatched(ImportClassPhotos::class, 1);
    Bus::assertDispatched(fn (ImportClassPhotos $job) => $job->class === '010');
});

it('reports open issues and failed jobs in the report', function () {
    PhotoIssue::create([
        'status' => 'open', 'issue_type' => 'missing_original', 'show_id' => '26AAC',
        'show_class_id' => '26AAC_005', 'source_path' => '26AAC/005/x.jpg',
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => 'fixed-uuid', 'connection' => 'redis', 'queue' => 'imports',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ]);

    $report = app(ShowSweep::class)->sweep(Show::find('26AAC'));

    expect($report['open_issues'])->toBe(1)
        ->and($report['failed_jobs'])->toBe(1);
});

it('summarises a busy sweep in plain sentences', function () {
    $message = app(ShowSweep::class)->message([
        'imports_queued' => ['005' => 16, '010' => 196],
        'generation_queued' => ['proofs' => 5, 'web' => 2, 'highres' => 1],
        'deliveries_queued' => ['005' => ['proofs'], '010' => ['proofs', 'web'], '012' => ['proofs']],
        'skipped_folders' => ['bad name' => 'The website will not accept this name. Suggested: bad_name'],
        'open_issues' => 2,
        'missing_originals' => 3,
        'failed_jobs' => 4,
        'notes' => ['The background workers are stopped: press Start in the header and this work begins.'],
    ]);

    expect($message)->toStartWith('Queued: 2 classes importing (212 photos), 5 proofs, 2 web images and 1 high-res image generating, 3 classes uploading.')
        ->toContain("Skipped 'bad name': The website will not accept this name. Suggested: bad_name.")
        ->toContain('2 photos need review on the Issues page.')
        ->toContain('3 photos cannot be generated because the original is not on this disk.')
        ->toContain('4 background jobs failed in the last day.')
        ->toContain('The background workers are stopped: press Start in the header and this work begins.');
});

it('uses singular wording for one item', function () {
    $message = app(ShowSweep::class)->message([
        'imports_queued' => ['005' => 1],
        'generation_queued' => ['proofs' => 1, 'web' => 0, 'highres' => 0],
        'deliveries_queued' => ['005' => ['proofs']],
        'skipped_folders' => [],
        'open_issues' => 1,
        'missing_originals' => 0,
        'failed_jobs' => 1,
        'notes' => [],
    ]);

    expect($message)->toBe('Queued: 1 class importing (1 photo), 1 proof generating, 1 class uploading. '
        .'1 photo needs review on the Issues page. 1 background job failed in the last day.');
});

it('keeps the header badge to a few words', function () {
    $sweep = app(ShowSweep::class);
    $empty = [
        'imports_queued' => [], 'generation_queued' => ['proofs' => 0, 'web' => 0, 'highres' => 0], 'deliveries_queued' => [],
        'skipped_folders' => [], 'open_issues' => 0, 'missing_originals' => 0, 'failed_jobs' => 0, 'notes' => [],
    ];

    expect($sweep->shortMessage($empty))->toBe('Nothing is pending.')
        ->and($sweep->shortMessage(['imports_queued' => ['005' => 16]] + $empty))->toBe('Work queued.')
        ->and($sweep->shortMessage(['imports_queued' => ['005' => 16], 'notes' => ['x']] + $empty))->toBe('Work queued; see the message.')
        ->and($sweep->shortMessage(['skipped_folders' => ['bad name' => 'x']] + $empty))->toBe('Nothing queued; see the message.');
});

it('ignores failed jobs from before yesterday', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => 'old-uuid', 'connection' => 'redis', 'queue' => 'imports',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(3),
    ]);

    expect(app(ShowSweep::class)->sweep(Show::find('26AAC'))['failed_jobs'])->toBe(0);
});
