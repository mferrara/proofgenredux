<?php

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Livewire\HomeComponent;
use App\Livewire\ShowViewComponent;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Ferraraphoto\FerraraphotoApiException;
use App\Services\Ferraraphoto\WebsiteShowMissingException;
use App\Services\Ferraraphoto\WebsiteShows;
use App\Services\Storage\ShowProfileBinder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Shows are created on the website. Once Gallery offers `GET /api/v1/shows`,
 * Proofgen picks among those shows and never creates one; an older Gallery
 * without the list keeps the create-through-the-API behavior.
 */

const GALLERY = 'https://gallery.test';

beforeEach(function () {
    config([
        'proofgen.ferraraphoto.base_url' => GALLERY,
        'proofgen.ferraraphoto.api_token' => 'test-token',
    ]);
    app()->forgetInstance(FerraraphotoApiClient::class);

    Show::withoutEvents(fn () => Show::create([
        'id' => 'TEST', 'name' => 'Test', 'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'TEST_001', 'show_id' => 'TEST', 'name' => '001']));
});

function websiteShow(string $slug, string $name): array
{
    return [
        'slug' => $slug, 'name' => $name, 'start_date' => '2026-09-18', 'end_date' => '2026-09-20',
        'storage_profile_id' => 'legacy-local', 'class_count' => 3, 'photo_count' => 120, 'hidden' => false,
    ];
}

function ensureShow(): void
{
    (new EnsureFerraraphotoShow('TEST'))->handle(app(FerraraphotoApiClient::class), app(ShowProfileBinder::class));
}

function postedPaths(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => $pair[0]->method() === 'POST')
        ->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))
        ->values()
        ->all();
}

it('never creates a show that is missing on a website that lists its shows', function () {
    Http::fake([
        GALLERY.'/api/v1/shows/TEST' => Http::response(['error' => ['code' => 'not_found', 'message' => 'Show not found.']], 404),
        GALLERY.'/api/v1/shows?*' => Http::response(['data' => [websiteShow('26AAC', 'Arizona Classic')]]),
    ]);

    expect(fn () => ensureShow())->toThrow(WebsiteShowMissingException::class, 'Create it on the website first');
    expect(postedPaths())->toBe([]);
});

it('still creates the show through the API on an older website without a show list', function () {
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404)
        : Http::response(['data' => []]));

    ensureShow();

    expect(postedPaths())->toBe(['/api/v1/shows', '/api/v1/shows/TEST/classes']);
});

it('keeps syncing a show that already exists on the website', function () {
    Http::fake(['*' => Http::response(['data' => ['slug' => 'TEST']])]);

    ensureShow();

    expect(postedPaths())->toBe(['/api/v1/shows', '/api/v1/shows/TEST/classes']);
});

it('turns a creation-disabled conflict into create-it-on-the-website-first', function () {
    Http::fake(fn ($request) => match (true) {
        $request->method() === 'GET' => Http::response(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404),
        default => Http::response(['error' => ['code' => 'show_creation_disabled', 'message' => 'Create it on the website first.']], 409),
    });

    expect(fn () => ensureShow())->toThrow(WebsiteShowMissingException::class);
    expect(postedPaths())->toBe(['/api/v1/shows']);
});

it('does not let one class the website rejects block the class being delivered', function () {
    ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'TEST_Halter_', 'show_id' => 'TEST', 'name' => 'Halter_']));

    Http::fake(fn ($request) => match (true) {
        $request->method() === 'GET' => Http::response(['data' => ['slug' => 'TEST']]),
        ($request['class_number'] ?? null) === 'Halter_' => Http::response(['error' => [
            'code' => 'validation_error',
            'message' => 'Payload schema invalid',
            'context' => ['errors' => ['class_number' => ['The class number field format is invalid.']]],
        ]], 422),
        default => Http::response(['data' => []]),
    });

    // Delivering class 001: the badly named sibling is skipped, not fatal.
    (new EnsureFerraraphotoShow('TEST', 'TEST_001'))->handle(app(FerraraphotoApiClient::class), app(ShowProfileBinder::class));

    // Delivering the rejected class itself fails, and says exactly why.
    expect(fn () => (new EnsureFerraraphotoShow('TEST', 'TEST_Halter_'))->handle(app(FerraraphotoApiClient::class), app(ShowProfileBinder::class)))
        ->toThrow(FerraraphotoApiException::class, 'Payload schema invalid [POST /shows/TEST/classes] class_number: The class number field format is invalid.');

    // A show-level sync still reports the rejection, after syncing the rest.
    expect(fn () => ensureShow())->toThrow(FerraraphotoApiException::class, 'class_number');
});

it('reports the list as absent without a request when no token is configured', function () {
    config(['proofgen.ferraraphoto.api_token' => null]);

    // Http::preventStrayRequests() is active: any request would throw.
    expect(app(WebsiteShows::class)->load()['status'])->toBe(WebsiteShows::ABSENT);
});

it('does not remember a failed lookup', function () {
    Http::fake([GALLERY.'/api/v1/shows*' => Http::response([], 502)]);

    expect(app(WebsiteShows::class)->load()['status'])->toBe(WebsiteShows::ERROR)
        ->and(app(WebsiteShows::class)->peek())->toBeNull();
});

it('offers website shows that have no folder yet when creating a show', function () {
    Storage::fake('fullsize');
    Storage::fake('archive');
    Storage::disk('fullsize')->makeDirectory('TEST');
    Http::fake([GALLERY.'/api/v1/shows*' => Http::response(['data' => [
        websiteShow('26AAC', 'Arizona Classic'),
        websiteShow('TEST', 'Already here'),
    ]])]);

    Livewire::test(HomeComponent::class)
        ->assertSee('Enter a folder name for the new show')
        ->call('loadWebsiteShows')
        ->assertSee('Shows must be created on the website first')
        ->assertSee(GALLERY.'/admin/shows/create')
        ->assertSee('Arizona Classic')
        ->assertDontSee('Already here')
        ->assertSee('Not listed yet?');
});

it('flags a show that is not on the website and lets the operator pick the matching one', function () {
    Storage::fake('fullsize');
    Storage::fake('archive');
    Storage::disk('fullsize')->makeDirectory('TEST/001');
    Http::fake([
        GALLERY.'/api/v1/shows/TEST' => Http::response(['error' => ['code' => 'not_found', 'message' => 'Show not found.']], 404),
        GALLERY.'/api/v1/shows?*' => Http::response(['data' => [websiteShow('26AAC', 'Arizona Classic')]]),
        GALLERY.'/api/v1/shows' => Http::response(['data' => [websiteShow('26AAC', 'Arizona Classic')]]),
        // Opening a show also asks where its files go; not what this test is about.
        GALLERY.'/api/v1/delivery-target*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404),
    ]);

    Livewire::test(ShowViewComponent::class, ['show_id' => 'TEST'])
        ->assertDontSee('not on website')
        ->call('loadWebsiteShows')
        ->assertSee('not on website')
        ->assertSee('Shows must be created on the website')
        ->call('startEditingFerraraphotoSlug')
        ->assertSee('Arizona Classic')
        ->set('ferraraphotoSlugDraft', '26AAC')
        ->call('saveFerraraphotoSlug')
        ->assertSee('on website')
        ->assertDontSee('not on website');

    expect(Show::find('TEST')->ferraraphoto_slug)->toBe('26AAC');
});
