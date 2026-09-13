<?php

use App\Livewire\AppStatusBar;
use App\Livewire\ConfigComponent;
use App\Services\HorizonService;
use App\Services\WorkerActivityService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('fullsize');
    config(['proofgen.archive_enabled' => false]);
    File::partialMock()->shouldReceive('exists')->with(storage_path('sample_images'))->andReturnFalse();
});

it('refreshes worker state and queue activity without a new page load', function () {
    $running = false;
    $waiting = 4;
    $horizon = Mockery::mock(HorizonService::class);
    // Capture references so an external worker change appears on the next poll.
    $horizon->shouldReceive('isRunning')->andReturnUsing(function () use (&$running) {
        return $running;
    });
    app()->instance(HorizonService::class, $horizon);
    $activity = Mockery::mock(WorkerActivityService::class);
    $activity->shouldReceive('snapshot')->andReturnUsing(function () use (&$waiting) {
        return ['available' => true, 'waiting' => $waiting, 'active' => 0, 'delayed' => 0];
    });
    app()->instance(WorkerActivityService::class, $activity);

    $component = Livewire::test(AppStatusBar::class)->assertSee('Stopped')->assertSee('4 queued');
    $running = true;
    $waiting = 0;
    $component->call('$refresh')->assertSee('Running')->assertSee('0 queued')->assertDontSee('Start workers to process queued work.');
});

it('updates the settings services status when workers change outside settings', function () {
    $horizon = Mockery::mock(HorizonService::class);
    $running = false;
    $horizon->shouldReceive('isRunning')->andReturnUsing(function () use (&$running) {
        return $running;
    });
    $horizon->shouldReceive('getProcessInfo')->andReturn(['running' => true, 'processes' => []]);
    app()->instance(HorizonService::class, $horizon);
    $component = Livewire::test(ConfigComponent::class)->assertSet('isHorizonRunning', false);
    $running = true;
    $component->call('updateHorizonStatus')->assertSet('isHorizonRunning', true);
});
