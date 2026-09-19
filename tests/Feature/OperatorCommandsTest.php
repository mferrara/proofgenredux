<?php

use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\ShowWorkSummary;
use App\Services\WorkerActivityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/*
 * The command-line tools an agent uses to help the photographer:
 * docs/OPERATING.md. They do what the Import and Upload buttons do.
 */

beforeEach(function () {
    config(['testing.skip_file_operations' => true]);
    Storage::fake('fullsize');
    Storage::fake('archive');

    Show::withoutEvents(fn () => Show::create(['id' => '26AAC', 'name' => '26AAC']));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => '26AAC_005', 'show_id' => '26AAC', 'name' => '005']));

    // 005: one photo proofed but not uploaded. 010: two files waiting. "Halter_": a name the website rejects.
    Photo::withoutEvents(fn () => Photo::create([
        'id' => '26AAC_005_26AAC_00001', 'show_class_id' => '26AAC_005', 'proof_number' => '26AAC_00001',
        'file_type' => 'jpg', 'sha1' => sha1('a'), 'proofs_generated_at' => now(),
    ]));
    Storage::disk('fullsize')->makeDirectory('26AAC/005');
    Storage::disk('fullsize')->put('26AAC/010/_Z5A0001.JPG', 'x');
    Storage::disk('fullsize')->put('26AAC/010/_Z5A0002.JPG', 'y');
    Storage::disk('fullsize')->put('26AAC/Halter_/_Z5A0003.JPG', 'z');
});

it('reports the next step for every class', function () {
    $this->artisan('proofgen:status', ['show' => '26AAC', '--json' => true])->assertSuccessful();

    $classes = collect(app(ShowWorkSummary::class)->classes(Show::find('26AAC')))
        ->mapWithKeys(fn ($row) => [$row['class'] => ShowWorkSummary::nextStep($row)]);

    expect($classes->all())->toBe([
        '005' => 'generating (wait, or check the workers)', // web and highres not generated yet
        '010' => 'import',
        'Halter_' => 'rename the folder',
    ]);
});

it('imports every class with photos waiting, except names the website would reject', function () {
    Bus::fake();

    $this->artisan('proofgen:import', ['show' => '26AAC', '--all' => true])
        ->expectsOutputToContain('010: 2 photos queued for import')
        ->expectsOutputToContain('Halter_: skipped')
        ->assertSuccessful();

    Bus::assertDispatched(ImportClassPhotos::class, 1);
    Bus::assertDispatched(fn (ImportClassPhotos $job) => $job->class === '010');
});

it('uploads what is ready, proofs on the proofs queue', function () {
    Bus::fake();

    $this->artisan('proofgen:upload', ['show' => '26AAC', '--all' => true])
        ->expectsOutputToContain('005: queued proofs')
        ->assertSuccessful();

    Bus::assertDispatched(DeliverClassOutputs::class, 1);
    Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->classId === '26AAC_005'
        && $job->kinds === ['proofs'] && $job->queue === config('proofgen.uploads.queue'));
});

it('refuses to guess which classes are meant', function () {
    $this->artisan('proofgen:import', ['show' => '26AAC'])->assertFailed();
    $this->artisan('proofgen:upload', ['show' => '26AAC'])->assertFailed();
    $this->artisan('proofgen:status', ['show' => 'NOPE'])->assertFailed();
    $this->artisan('proofgen:process', ['show' => 'NOPE'])->assertFailed();
});

it('sweeps everything pending with one process command', function () {
    Bus::fake();

    $this->artisan('proofgen:process', ['show' => '26AAC'])
        ->expectsOutputToContain('010: 2 photos queued for import')
        ->expectsOutputToContain('005: queued proofs for upload')
        ->expectsOutputToContain("Skipped 'Halter_':")
        ->assertSuccessful();

    Bus::assertDispatched(ImportClassPhotos::class, fn ($job) => $job->class === '010' && $job->queue === 'imports');
    Bus::assertDispatched(DeliverClassOutputs::class, fn ($job) => $job->classId === '26AAC_005' && $job->kinds === ['proofs']);
    Bus::assertNotDispatched(ImportClassPhotos::class, fn ($job) => $job->class === 'Halter_');
});

it('prints a valid json report with --json', function () {
    Bus::fake();

    Artisan::call('proofgen:process', ['show' => '26AAC', '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($report['imports_queued'])->toBe(['010' => 2])
        ->and($report['deliveries_queued'])->toBe(['005' => ['proofs']])
        ->and($report['skipped_folders'])->toHaveKey('Halter_')
        ->and($report['generation_queued'])->toBe(['proofs' => 0, 'web' => 0, 'highres' => 0])
        ->and($report)->toHaveKeys(['open_issues', 'missing_originals', 'failed_jobs', 'notes']);
});

it('points at proofgen:process when nothing is queued but photos still need generating', function () {
    $activity = Mockery::mock(WorkerActivityService::class);
    $activity->shouldReceive('snapshot')->andReturn(['available' => true, 'waiting' => 0, 'active' => 0, 'delayed' => 0]);
    app()->instance(WorkerActivityService::class, $activity);

    // 005's photos are proofed but web/highres are missing: "generating".
    $this->artisan('proofgen:status', ['show' => '26AAC'])
        ->expectsOutputToContain('Run: php artisan proofgen:process 26AAC')
        ->assertSuccessful();
});

it('does not suggest proofgen:process while the queue is moving', function () {
    $activity = Mockery::mock(WorkerActivityService::class);
    $activity->shouldReceive('snapshot')->andReturn(['available' => true, 'waiting' => 3, 'active' => 1, 'delayed' => 0]);
    app()->instance(WorkerActivityService::class, $activity);

    $this->artisan('proofgen:status', ['show' => '26AAC'])
        ->doesntExpectOutputToContain('Run: php artisan proofgen:process 26AAC')
        ->assertSuccessful();
});
