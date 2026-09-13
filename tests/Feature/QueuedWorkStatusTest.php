<?php

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\Photo\ImportPhoto;
use App\Jobs\ShowClass\ImportClassPhotos;
use App\Jobs\ShowClass\UploadDerivedFiles;
use App\Livewire\ClassViewComponent;
use App\Livewire\ShowViewComponent;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\QueuedWorkStatus;
use App\Services\StorageUsageService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config(['testing.skip_file_operations' => true]);
    Storage::fake('fullsize');
    Storage::fake('archive');
    config([
        'proofgen.fullsize_home_dir' => Storage::disk('fullsize')->path(''),
        'proofgen.archive_home_dir' => Storage::disk('archive')->path(''),
    ]);
    Show::withoutEvents(fn () => Show::create(['id' => 'DAD_SHOW', 'name' => 'DAD_SHOW']));
    foreach (['001_A', '002'] as $name) {
        ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'DAD_SHOW_'.$name, 'show_id' => 'DAD_SHOW', 'name' => $name]));
        Storage::disk('fullsize')->makeDirectory('DAD_SHOW/'.$name);
    }
    $this->photo = Photo::create(['show_class_id' => 'DAD_SHOW_001_A', 'proof_number' => '100', 'file_type' => 'jpg']);
});

function queuedWorkPayload(object $job, string $uuid = ''): string
{
    return json_encode(['uuid' => $uuid ?: uniqid(), 'data' => ['command' => serialize($job)]]);
}

function fakeQueuedWork(array $payloads): QueuedWorkStatus
{
    $service = Mockery::mock(QueuedWorkStatus::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('queuedPayloads')->andReturn($payloads);
    app()->instance(QueuedWorkStatus::class, $service);

    return $service;
}

it('tracks pending imports, active derivatives and delayed retries by exact class identity', function () {
    $status = fakeQueuedWork([
        ['waiting', queuedWorkPayload(new ImportPhoto('DAD_SHOW/001_A/camera.jpg'))],
        ['active', queuedWorkPayload(new GenerateThumbnails($this->photo->id, 'proofs'))],
        ['delayed', queuedWorkPayload(new ImportClassPhotos('DAD_SHOW', '001_A'))],
        ['waiting', queuedWorkPayload(new ImportPhoto('DAD_SHOW/002/other.jpg'))],
        ['waiting', queuedWorkPayload(new ImportPhoto('DAD_SHOW_OTHER/001_A/other.jpg'))],
    ]);

    expect($status->snapshot('DAD_SHOW', '001_A'))
        ->toMatchArray(['available' => true, 'busy' => true, 'waiting' => 1, 'active' => 1, 'delayed' => 1]);
    expect($status->snapshot('DAD_SHOW', '002'))->toMatchArray(['waiting' => 1, 'active' => 0, 'delayed' => 0]);
    expect($status->snapshot('DAD_SHOW', '003')['busy'])->toBeFalse();
    expect($status->snapshot('DAD_SHOW')['waiting'])->toBe(2);
});

it('keeps chained uploads busy before their class job reaches the upload queue', function () {
    $chain = (new EnsureFerraraphotoShow('DAD_SHOW'))->chain([new UploadDerivedFiles('DAD_SHOW_001_A')]);
    $payload = queuedWorkPayload($chain, 'one-job');
    $status = fakeQueuedWork([['waiting', $payload], ['active', $payload]]);
    expect($status->snapshot('DAD_SHOW', '001_A'))->toMatchArray(['busy' => true, 'waiting' => 1, 'active' => 0]);
    expect($status->snapshot('DAD_SHOW', '002')['busy'])->toBeFalse();
});

it('reports queue failures as unavailable instead of idle', function () {
    $service = Mockery::mock(QueuedWorkStatus::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('queuedPayloads')->andThrow(new RuntimeException('Redis unavailable'));
    expect($service->snapshot('DAD_SHOW'))->toMatchArray(['available' => false, 'busy' => true]);
});

it('blocks every class pipeline action on the server while an import is queued', function () {
    Queue::fake();
    fakeQueuedWork([['waiting', queuedWorkPayload(new ImportPhoto('DAD_SHOW/001_A/camera.jpg'))]]);
    $component = Livewire::test(ClassViewComponent::class, ['show' => 'DAD_SHOW', 'class' => '001_A'])
        ->assertSee('Work in progress:');
    foreach (['importPendingImages', 'proofPendingPhotos', 'webImagePendingPhotos', 'highresImagePendingPhotos',
        'regenerateProofs', 'regenerateWebImages', 'regenerateHighresImages', 'resetPhotos',
        'uploadPendingProofsAndWebImages', 'checkProofAndWebImageUploads'] as $method) {
        $component->call($method)->assertSet('flash_message', 'Work is already queued or running. Please wait for it to finish.');
    }
    foreach (['proofPhoto', 'generateWebImage', 'generateHighresImage'] as $method) {
        $component->call($method, $this->photo->id)->assertSet('flash_message', 'Work is already queued or running. Please wait for it to finish.');
    }
    $component->call('processImage', 'DAD_SHOW/001_A/camera.jpg');
    Queue::assertNothingPushed();

    $html = $component->html();
    expect($html)->toMatch('/<fieldset[^>]*disabled[^>]*wire:loading.attr="disabled"/s');
});

it('blocks show-wide actions but allows importing an unrelated class', function () {
    Queue::fake();
    fakeQueuedWork([['active', queuedWorkPayload(new ImportClassPhotos('DAD_SHOW', '001_A'))]]);
    $component = Livewire::test(ShowViewComponent::class, ['show_id' => 'DAD_SHOW']);
    foreach (['importPendingImages', 'proofPendingPhotos', 'webImagePendingPhotos', 'highresImagePendingPhotos',
        'regenerateProofs', 'regenerateWebImages', 'regenerateHighresImages', 'resetPhotos',
        'uploadPendingProofs', 'uploadPendingProofsAndWebImages', 'checkProofAndWebImageUploads'] as $method) {
        $component->call($method)->assertSet('flash_message', 'Work is already queued or running. Please wait for it to finish.');
    }
    $component->call('processPendingClassImages', '001_A');
    Queue::assertNothingPushed();
    $component->call('processPendingClassImages', '002');
    Queue::assertPushed(ImportClassPhotos::class, fn ($job) => $job->class === '002');
    Queue::assertPushed(ImportClassPhotos::class, 1);
});

it('re-enables class actions on the next poll when the queue drains', function () {
    $service = Mockery::mock(QueuedWorkStatus::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('queuedPayloads')->andReturn(
        [['active', queuedWorkPayload(new ImportClassPhotos('DAD_SHOW', '001_A'))]],
        [],
    );
    app()->instance(QueuedWorkStatus::class, $service);
    Livewire::test(ClassViewComponent::class, ['show' => 'DAD_SHOW', 'class' => '001_A'])
        ->assertSee('Work in progress:')->call('$refresh')->assertDontSee('Work in progress:');
});

it('displays retained stale storage on a new page and refreshes it only on request', function (string $componentClass, array $params, string $scope) {
    fakeQueuedWork([]);
    $service = app(StorageUsageService::class);
    Storage::disk('fullsize')->put('proofs/DAD_SHOW/001_A/100_std.jpg', 'synthetic');
    if ($scope === 'class') {
        $service->classUsage(ShowClass::find('DAD_SHOW_001_A'));
    } else {
        $service->showUsage(Show::find('DAD_SHOW'));
    }
    $this->travel(11)->minutes();
    Storage::disk('fullsize')->put('proofs/DAD_SHOW/001_A/101_std.jpg', str_repeat('x', 100));

    // Use a measured directory that class discovery does not import from.
    $component = Livewire::test($componentClass, $params)
        ->assertSee('Stale')->assertSee('9 B')->call('$refresh')->assertSee('Stale')->assertSee('9 B');
    $component->call('refreshStorageUsage')->assertDontSee('Stale')->assertSee('109 B');
})->with([
    'show' => [ShowViewComponent::class, ['show_id' => 'DAD_SHOW'], 'show'],
    'class' => [ClassViewComponent::class, ['show' => 'DAD_SHOW', 'class' => '001_A'], 'class'],
]);
