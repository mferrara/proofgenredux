<?php

use App\Livewire\UpdateBanner;
use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    @unlink(storage_path('app/update-notice.json'));
    Cache::flush();

    $this->updates = Mockery::mock(UpdateService::class);
    $this->app->instance(UpdateService::class, $this->updates);
    $this->available = ['current_version' => 'v2.5.2', 'latest_version' => 'v2.6.0', 'update_available' => true];
});

afterEach(fn () => @unlink(storage_path('app/update-notice.json')));

it('stays hidden until a check has found a newer version', function () {
    $this->updates->shouldReceive('checkForUpdates')->once()->andReturn($this->available);

    Livewire::test(UpdateBanner::class)
        ->assertDontSee('is available')
        ->call('check')
        ->assertSee('v2.6.0')->assertSee('Update now')->assertSee('Remind me later')->assertSee('Skip this version')
        // A second page load inside the window must not fetch again.
        ->call('check');
});

it('says nothing when Proofgen is up to date', function () {
    $this->updates->shouldReceive('checkForUpdates')->once()
        ->andReturn(['current_version' => 'v2.6.0', 'latest_version' => 'v2.6.0', 'update_available' => false]);

    Livewire::test(UpdateBanner::class)->call('check')->assertDontSee('is available');
});

it('hides for a few hours on remind me later and comes back afterwards', function () {
    $this->updates->shouldReceive('checkForUpdates')->andReturn($this->available);

    Livewire::test(UpdateBanner::class)->call('check')->call('remindLater')->assertDontSee('is available');

    $this->travel(5)->hours();
    Livewire::test(UpdateBanner::class)->call('check')->assertSee('v2.6.0');
});

it('skips one version but offers the next', function () {
    $this->updates->shouldReceive('checkForUpdates')->andReturn($this->available, ['latest_version' => 'v2.6.1'] + $this->available);

    Livewire::test(UpdateBanner::class)->call('check')->call('skipVersion')->assertDontSee('is available');

    $this->travel(1)->hours();
    Livewire::test(UpdateBanner::class)->call('check')->assertSee('v2.6.1');
});

it('runs the updater from the bar and reports a failure without hiding it', function () {
    $this->updates->shouldReceive('checkForUpdates')->andReturn($this->available);
    $this->updates->shouldReceive('performUpdate')->once()
        ->andReturn(['success' => false, 'steps' => [], 'error' => 'Git pull failed', 'backup_dir' => null]);

    Livewire::test(UpdateBanner::class)->call('check')->call('updateNow')->call('check')->assertSee('v2.6.0');
});

it('sits at the top of a real page, above the navigation', function () {
    Cache::put('update-notice.check', $this->available, now()->addMinutes(30));

    $html = $this->actingAs(App\Models\User::factory()->create())->get('/')->assertOk()->getContent();

    expect($html)->toContain('Update now')
        ->and(strpos($html, 'Update now'))->toBeLessThan(strpos($html, 'wire:name="navigation-menu"'));
});
