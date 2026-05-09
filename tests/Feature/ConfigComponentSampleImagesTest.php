<?php

use App\Livewire\ConfigComponent;
use App\Services\SampleImagesService;
use Livewire\Livewire;

beforeEach(function () {
    // ConfigComponent::mount() generates thumbnail previews from any image found in
    // storage/sample_images, which can blow past the default 128M PHPUnit memory limit
    // when full-size sample photos are present on disk. Bump it for this test only.
    ini_set('memory_limit', '512M');
});

afterEach(function () {
    Mockery::close();
});

it('invokes the sample images service and dispatches a success toast with the count', function () {
    $mock = Mockery::mock(SampleImagesService::class);
    $mock->shouldReceive('downloadSampleImages')->once()->andReturn(7);
    $this->app->instance(SampleImagesService::class, $mock);

    Livewire::test(ConfigComponent::class)
        ->call('downloadSampleImages')
        ->assertDispatched('toast-show', function ($event, $params) {
            return ($params['slots']['heading'] ?? null) === 'Sample Images Downloaded'
                && ($params['dataset']['variant'] ?? null) === 'success'
                && str_contains($params['slots']['text'] ?? '', '7 sample images');
        });
});

it('dispatches a danger toast when the service throws', function () {
    $mock = Mockery::mock(SampleImagesService::class);
    $mock->shouldReceive('downloadSampleImages')
        ->once()
        ->andThrow(new Exception('bucket unavailable'));
    $this->app->instance(SampleImagesService::class, $mock);

    Livewire::test(ConfigComponent::class)
        ->call('downloadSampleImages')
        ->assertDispatched('toast-show', function ($event, $params) {
            return ($params['slots']['heading'] ?? null) === 'Download Failed'
                && ($params['dataset']['variant'] ?? null) === 'danger'
                && str_contains($params['slots']['text'] ?? '', 'bucket unavailable');
        });
});

it('singularizes the count message when only one image was downloaded', function () {
    $mock = Mockery::mock(SampleImagesService::class);
    $mock->shouldReceive('downloadSampleImages')->once()->andReturn(1);
    $this->app->instance(SampleImagesService::class, $mock);

    Livewire::test(ConfigComponent::class)
        ->call('downloadSampleImages')
        ->assertDispatched('toast-show', function ($event, $params) {
            return str_contains($params['slots']['text'] ?? '', '1 sample image.')
                && ! str_contains($params['slots']['text'] ?? '', '1 sample images');
        });
});
