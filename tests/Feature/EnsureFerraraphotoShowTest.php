<?php

use App\Jobs\Ferraraphoto\EnsureFerraraphotoShow;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Storage\ShowProfileBinder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

it('uses the seeded legacy profile and registers custom profiles before syncing a show', function (bool $legacy) {
    config(['proofgen.ferraraphoto.api_token' => 'test-token']);
    $profile = $legacy
        ? StorageProfile::findOrFail(StorageProfile::LEGACY_LOCAL_ID)
        : StorageProfile::factory()->create(['id' => 'cloud']);

    Show::withoutEvents(fn () => Show::create([
        'id' => 'TEST', 'name' => 'Test', 'storage_profile_id' => $profile->id,
    ]));
    ShowClass::withoutEvents(fn () => ShowClass::create([
        'id' => 'TEST_001', 'show_id' => 'TEST', 'name' => '001',
    ]));

    // Match beta's rejection of the built-in profile's sentinel fingerprint
    // and null root. Custom profiles still use the registration endpoint.
    Http::fake(fn ($request) => Http::response(
        str_ends_with($request->url(), '/storage-profiles') && $request['id'] === 'legacy-local'
            ? ['error' => ['code' => 'validation_error', 'message' => 'Payload schema invalid']]
            : ['data' => []],
        str_ends_with($request->url(), '/storage-profiles') && $request['id'] === 'legacy-local' ? 422 : 200,
    ));

    (new EnsureFerraraphotoShow('TEST'))->handle(
        new FerraraphotoApiClient('https://gallery.test', 'test-token', Log::getLogger()),
        app(ShowProfileBinder::class),
    );

    // Writes only: the job first reads the show to learn whether it exists.
    $paths = Http::recorded()
        ->filter(fn ($pair) => $pair[0]->method() === 'POST')
        ->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))
        ->values()
        ->all();
    expect($paths)->toBe($legacy
        ? ['/api/v1/shows', '/api/v1/shows/TEST/classes']
        : ['/api/v1/storage-profiles', '/api/v1/shows', '/api/v1/shows/TEST/classes']);
    Http::assertSent(fn ($request) => $request->url() === 'https://gallery.test/api/v1/shows'
        && $request['storage_profile_id'] === $profile->id);
})->with([true, false]);

it('builds SFTP connections with a numeric environment port', function () {
    $original = getenv('SFTP_PORT');
    putenv('SFTP_PORT=2222');

    try {
        $disks = (require base_path('config/filesystems.php'))['disks'];
        foreach (['remote_proofs', 'remote_web_images', 'remote_highres_images'] as $name) {
            $disk = array_replace($disks[$name], ['host' => 'example.test', 'username' => 'test']);
            expect($disk['port'])->toBe(2222);
            // Construction performs no connection, but rejects string ports.
            expect(SftpConnectionProvider::fromArray($disk))
                ->toBeInstanceOf(SftpConnectionProvider::class);
        }
    } finally {
        putenv($original === false ? 'SFTP_PORT' : 'SFTP_PORT='.$original);
    }
});
