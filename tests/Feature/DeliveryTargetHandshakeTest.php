<?php

namespace Tests\Feature;

use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Livewire\ShowViewComponent;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Models\StorageProfile;
use App\Services\Delivery\DeliveryTarget;
use App\Services\Delivery\DeliveryTargetDisks;
use App\Services\Delivery\DeliveryTargetException;
use App\Services\Delivery\DeliveryTargetResolver;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Transport\RsyncRunner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gallery-owned delivery destination (`GET /api/v1/delivery-target`): the
 * fallback rule, the saved per-show baseline, change detection, and the
 * destination actually handed to rsync. The API is always faked and no SSH
 * connection is ever made.
 */
class DeliveryTargetHandshakeTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://gallery.test';

    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/delivery_target_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);

        config([
            'testing.skip_file_operations' => true,
            'proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize',
            'proofgen.ferraraphoto.base_url' => self::ORIGIN,
            'proofgen.ferraraphoto.api_token' => 'test-token',
            'proofgen.sftp.driver' => 'sftp',
            'proofgen.sftp.host' => 'old-host.example',
            'proofgen.sftp.port' => 22,
            'proofgen.sftp.username' => 'forge',
            'proofgen.sftp.private_key' => '/Users/dad/.ssh/id_proofgen',
            'proofgen.sftp.path' => '/staging/proofs',
            'proofgen.sftp.web_images_path' => '/staging/web_images',
            'proofgen.sftp.highres_images_path' => '/staging/highres_images',
            'proofgen.thumbnails.small.suffix' => '_thm',
            'proofgen.thumbnails.large.suffix' => '_std',
            'proofgen.web_images.suffix' => '_web',
            'proofgen.highres_images.suffix' => '_highres',
            'filesystems.disks.fullsize' => ['driver' => 'local', 'root' => $this->tempPath.'/fullsize', 'throw' => true],
        ]);
        Storage::forgetDisk('fullsize');

        // The client is a singleton built from config at first resolve.
        app()->forgetInstance(FerraraphotoApiClient::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    private function makeShow(string $id = '26Test01', ?string $remoteSlug = null): Show
    {
        return Show::withoutEvents(fn () => Show::create([
            'id' => $id,
            'name' => $id,
            'ferraraphoto_show_slug' => $remoteSlug,
            'storage_profile_id' => StorageProfile::LEGACY_LOCAL_ID,
        ]));
    }

    private function makeClass(Show $show, string $name): ShowClass
    {
        return ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => $show->id.'_'.$name,
            'show_id' => $show->id,
            'name' => $name,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides  dot-keyed overrides; a null value removes the key
     * @return array<string, mixed>
     */
    private function handshake(string $slug = '26Test01', array $overrides = [], string $root = '/mnt/photo-storage'): array
    {
        $data = [
            'schema_version' => 1,
            'show' => ['slug' => $slug, 'exists' => true, 'storage_profile_id' => 'legacy-local'],
            'storage_profile' => ['id' => 'legacy-local', 'label' => 'Server filesystem', 'driver' => 'local'],
            'transport' => ['driver' => 'rsync_ssh', 'host' => 'origin.example', 'port' => 22, 'username' => 'forge'],
            'layout' => 'proofgen-v1',
            'destinations' => [
                'proofs' => ['directory' => $root.'/proofs/'.$slug, 'key_prefix' => 'proofs/'.$slug.'/'],
                'web_images' => ['directory' => $root.'/web_images/'.$slug, 'key_prefix' => 'web_images/'.$slug.'/'],
                'highres_images' => ['directory' => $root.'/highres_images/'.$slug, 'key_prefix' => 'highres_images/'.$slug.'/'],
            ],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                data_forget($data, $key);
            } else {
                data_set($data, $key, $value);
            }
        }

        return ['data' => $data];
    }

    private function fakeHandshake(array $body, int $status = 200): void
    {
        Http::fake([self::ORIGIN.'/api/v1/delivery-target*' => Http::response($body, $status)]);
    }

    private function markUploaded(ShowClass $class): void
    {
        Photo::withoutEvents(fn () => Photo::create([
            'id' => $class->id.'_P1',
            'show_class_id' => $class->id,
            'proof_number' => 'P1',
            'file_type' => 'jpg',
            'sha1' => sha1($class->id),
            'proofs_generated_at' => now()->subHour(),
            'proofs_uploaded_at' => now()->subMinutes(30),
        ]));
    }

    public function test_uses_gallerys_destination_for_the_remote_slug_and_saves_it_as_the_baseline(): void
    {
        $show = $this->makeShow('26Buck', 'buck-show-2026');
        $this->fakeHandshake($this->handshake('buck-show-2026'));

        $target = app(DeliveryTargetResolver::class)->refresh($show);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::ORIGIN.'/api/v1/delivery-target')
            && $request['show_slug'] === 'buck-show-2026');

        expect($target->isFromGallery())->toBeTrue()
            ->and($target->host)->toBe('origin.example')
            ->and($target->directory('proofs'))->toBe('/mnt/photo-storage/proofs/buck-show-2026')
            ->and($target->directory('web_images'))->toBe('/mnt/photo-storage/web_images/buck-show-2026')
            ->and($target->directory('highres_images'))->toBe('/mnt/photo-storage/highres_images/buck-show-2026');

        // The saved baseline serves later reads with no further HTTP.
        Http::fake(fn () => throw new \RuntimeException('current() must not call the API'));
        $current = app(DeliveryTargetResolver::class)->current($show->fresh());

        expect($current->isFromGallery())->toBeTrue()
            ->and($current->differsFrom($target))->toBeFalse();
    }

    public function test_rsync_goes_to_gallerys_directory_plus_the_class_folder_with_the_local_key(): void
    {
        $show = $this->makeShow();
        $class = $this->makeClass($show, '001_A');
        $this->fakeHandshake($this->handshake());
        app(DeliveryTargetResolver::class)->refresh($show);

        $class = $class->fresh();

        expect($class->rsyncProofsCommand())
            ->toContain("'forge@origin.example:/mnt/photo-storage/proofs/26Test01/001_A'")
            ->toContain('-i /Users/dad/.ssh/id_proofgen')
            ->not->toContain('staging')
            ->and($class->rsyncWebImagesCommand())->toContain("'forge@origin.example:/mnt/photo-storage/web_images/26Test01/001_A'")
            ->and($class->rsyncHighresImagesCommand())->toContain("'forge@origin.example:/mnt/photo-storage/highres_images/26Test01/001_A'")
            ->and($show->fresh()->rsyncProofsCommand())->toContain("'forge@origin.example:/mnt/photo-storage/proofs/26Test01'");
    }

    public function test_falls_back_to_local_settings_when_gallery_has_no_endpoint(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404);

        $target = app(DeliveryTargetResolver::class)->refresh($show);

        expect($target->isFromGallery())->toBeFalse()
            ->and($target->host)->toBe('old-host.example')
            ->and($target->directory('proofs'))->toBe('/staging/proofs/26Test01')
            ->and($show->fresh()->delivery_target)->toBeNull();
    }

    public function test_never_calls_the_api_without_a_token_or_with_the_local_driver(): void
    {
        $show = $this->makeShow();

        // Http::preventStrayRequests() is active: any request would throw.
        config(['proofgen.ferraraphoto.api_token' => null]);
        expect(app(DeliveryTargetResolver::class)->refresh($show)->isFromGallery())->toBeFalse();

        config(['proofgen.ferraraphoto.api_token' => 'test-token', 'proofgen.sftp.driver' => 'local']);
        $target = app(DeliveryTargetResolver::class)->refresh($show);

        expect($target->isFromGallery())->toBeFalse()
            ->and($target->usesSsh())->toBeFalse();
    }

    public function test_keeps_the_local_host_when_gallery_omits_transport(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake($this->handshake(overrides: ['transport' => null]));

        $target = app(DeliveryTargetResolver::class)->refresh($show);

        expect($target->isFromGallery())->toBeTrue()
            ->and($target->host)->toBe('old-host.example')
            ->and($target->directory('proofs'))->toBe('/mnt/photo-storage/proofs/26Test01');
    }

    public function test_an_unavailable_target_stops_delivery_and_is_retryable(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake(['error' => ['code' => 'no_upload_target', 'message' => 'No usable profile.']], 503);

        try {
            app(DeliveryTargetResolver::class)->refresh($show);
            $this->fail('Expected a DeliveryTargetException.');
        } catch (DeliveryTargetException $exception) {
            expect($exception->retryable)->toBeTrue();
        }

        expect($show->fresh()->delivery_target)->toBeNull();
    }

    public function test_a_gallery_outage_delivers_to_the_answer_gallery_already_gave(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake($this->handshake());
        $resolver = app(DeliveryTargetResolver::class);
        $resolver->refresh($show);

        // A deploy or a slow response mid-show: 502 on every attempt.
        $this->fakeHandshakeSequence(['message' => 'Bad gateway'], 502);

        $target = $resolver->refresh($show->fresh());

        expect($target->isFromGallery())->toBeTrue()
            ->and($target->directory('proofs'))->toBe('/mnt/photo-storage/proofs/26Test01')
            ->and($show->fresh()->delivery_target_pending)->toBeNull();
    }

    public function test_an_outage_does_not_trust_an_answer_from_another_api_origin(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake($this->handshake());
        app(DeliveryTargetResolver::class)->refresh($show);

        config(['proofgen.ferraraphoto.base_url' => 'https://production.test']);
        app()->forgetInstance(FerraraphotoApiClient::class);
        Http::fake(['https://production.test/api/v1/delivery-target*' => Http::response([], 502)]);

        expect(fn () => app(DeliveryTargetResolver::class)->refresh($show->fresh()))
            ->toThrow(fn (DeliveryTargetException $exception) => expect($exception->retryable)->toBeTrue());
    }

    public function test_a_rejected_token_still_stops_delivery_even_with_a_saved_answer(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake($this->handshake());
        $resolver = app(DeliveryTargetResolver::class);
        $resolver->refresh($show);

        $this->fakeHandshakeSequence(['error' => ['code' => 'unauthenticated', 'message' => 'Invalid token.']], 401);

        expect(fn () => $resolver->refresh($show->fresh()))
            ->toThrow(fn (DeliveryTargetException $exception) => expect($exception->retryable)->toBeFalse());
    }

    public function test_gallery_sends_an_explicit_null_transport_when_its_ssh_settings_are_unset(): void
    {
        $show = $this->makeShow();
        $body = $this->handshake();
        $body['data']['transport'] = null;
        $this->fakeHandshake($body);

        $target = app(DeliveryTargetResolver::class)->refresh($show);

        expect($target->isFromGallery())->toBeTrue()
            ->and($target->host)->toBe('old-host.example')
            ->and($target->username)->toBe('forge')
            ->and($target->directory('highres_images'))->toBe('/mnt/photo-storage/highres_images/26Test01');
    }

    public function test_a_show_on_a_non_local_profile_has_null_transport_and_destinations_and_is_not_guessed(): void
    {
        $show = $this->makeShow();
        $body = $this->handshake();
        $body['data']['show']['storage_profile_id'] = 'cloud';
        $body['data']['storage_profile'] = ['id' => 'cloud', 'label' => 'Cloud bucket', 'driver' => 's3'];
        $body['data']['transport'] = null;
        $body['data']['destinations'] = null;
        $this->fakeHandshake($body);

        expect(fn () => app(DeliveryTargetResolver::class)->refresh($show))
            ->toThrow(fn (DeliveryTargetException $exception) => expect($exception->retryable)->toBeFalse());

        expect($show->fresh()->delivery_target)->toBeNull();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unsupportedHandshakes(): array
    {
        return [
            'newer schema' => [['schema_version' => 2]],
            'unknown layout' => [['layout' => 'proofgen-v2']],
            'unknown transport' => [['transport.driver' => 's3']],
            'transport without host' => [['transport.host' => '']],
            'non-rsync profile' => [['storage_profile.driver' => 's3']],
            'relative directory' => [['destinations.web_images.directory' => 'web_images/26Test01']],
            'missing directory' => [['destinations.highres_images' => null]],
        ];
    }

    #[DataProvider('unsupportedHandshakes')]
    public function test_an_unsupported_handshake_stops_delivery_without_retrying(array $overrides): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake($this->handshake(overrides: $overrides));

        try {
            app(DeliveryTargetResolver::class)->refresh($show);
            $this->fail('Expected a DeliveryTargetException.');
        } catch (DeliveryTargetException $exception) {
            expect($exception->retryable)->toBeFalse();
        }

        expect($show->fresh()->delivery_target)->toBeNull();
    }

    public function test_an_invalid_slug_is_not_retried(): void
    {
        $show = $this->makeShow();
        $this->fakeHandshake(['error' => ['code' => 'validation_failed', 'message' => 'Invalid slug.']], 422);

        expect(fn () => app(DeliveryTargetResolver::class)->refresh($show))
            ->toThrow(fn (DeliveryTargetException $exception) => expect($exception->retryable)->toBeFalse());
    }

    public function test_a_changed_destination_waits_for_the_operator_once_files_were_uploaded(): void
    {
        $show = $this->makeShow();
        $class = $this->makeClass($show, '001');
        $this->fakeHandshake($this->handshake());
        $resolver = app(DeliveryTargetResolver::class);
        $resolver->refresh($show);
        $this->markUploaded($class);

        $this->fakeHandshakeSequence($this->handshake(root: '/mnt/new-volume'));

        try {
            $resolver->refresh($show->fresh());
            $this->fail('Expected a DeliveryTargetException.');
        } catch (DeliveryTargetException $exception) {
            expect($exception->retryable)->toBeFalse();
        }

        $show = $show->fresh();
        expect($resolver->current($show)->directory('proofs'))->toBe('/mnt/photo-storage/proofs/26Test01')
            ->and($resolver->pending($show)?->directory('proofs'))->toBe('/mnt/new-volume/proofs/26Test01');

        $resolver->acceptPending($show);
        $show = $show->fresh();

        expect($resolver->current($show)->directory('proofs'))->toBe('/mnt/new-volume/proofs/26Test01')
            ->and($resolver->pending($show))->toBeNull();

        // Gallery still reports the accepted destination: delivery proceeds.
        expect($resolver->refresh($show)->directory('proofs'))->toBe('/mnt/new-volume/proofs/26Test01');
    }

    public function test_a_changed_destination_is_adopted_silently_before_any_upload(): void
    {
        $show = $this->makeShow();
        $this->makeClass($show, '001');
        $this->fakeHandshake($this->handshake());
        $resolver = app(DeliveryTargetResolver::class);
        $resolver->refresh($show);

        $this->fakeHandshakeSequence($this->handshake(root: '/mnt/new-volume'));

        expect($resolver->refresh($show->fresh())->directory('proofs'))->toBe('/mnt/new-volume/proofs/26Test01')
            ->and($resolver->pending($show->fresh()))->toBeNull();
    }

    public function test_the_same_destination_from_another_api_origin_is_not_a_change(): void
    {
        $show = $this->makeShow();
        $class = $this->makeClass($show, '001');
        $this->fakeHandshake($this->handshake());
        app(DeliveryTargetResolver::class)->refresh($show);
        $this->markUploaded($class);

        // Beta and production share one filesystem: only the API origin moves.
        config(['proofgen.ferraraphoto.base_url' => 'https://production.test']);
        app()->forgetInstance(FerraraphotoApiClient::class);
        Http::fake(['https://production.test/api/v1/delivery-target*' => Http::response($this->handshake())]);

        $target = app(DeliveryTargetResolver::class)->refresh($show->fresh());

        expect($target->apiOrigin)->toBe('https://production.test')
            ->and($show->fresh()->delivery_target_pending)->toBeNull();
    }

    public function test_delivery_stops_before_rsync_when_the_destination_changed(): void
    {
        $show = $this->makeShow();
        $class = $this->makeClass($show, '001');
        $this->fakeHandshake($this->handshake());
        app(DeliveryTargetResolver::class)->refresh($show);
        $this->markUploaded($class);

        $marker = $this->tempPath.'/rsync-was-run';
        $this->bindRecordingRsync($marker);
        $this->swapRemoteDisks();

        $this->fakeHandshakeSequence($this->handshake(root: '/mnt/new-volume'));
        Http::fake([
            '*' => Http::response(['data' => []]),
        ]);

        expect(fn () => (new DeliverClassOutputs($class->id))->handle())
            ->toThrow(DeliveryTargetException::class);

        expect(File::exists($marker))->toBeFalse();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/photos'));
    }

    public function test_delivery_rsyncs_each_kind_to_gallerys_directory(): void
    {
        $show = $this->makeShow();
        $class = $this->makeClass($show, '001');
        $stamp = now()->subMinute();

        Photo::withoutEvents(fn () => Photo::create([
            'id' => $class->id.'_P1',
            'show_class_id' => $class->id,
            'proof_number' => 'P1',
            'file_type' => 'jpg',
            'sha1' => sha1('P1'),
            'proofs_generated_at' => $stamp,
            'web_image_generated_at' => $stamp,
            'highres_image_generated_at' => $stamp,
        ]));
        foreach (['proofs', 'web_images', 'highres_images'] as $kind) {
            File::makeDirectory($this->tempPath.'/fullsize/'.$kind.'/26Test01/001', 0755, true);
        }

        $marker = $this->tempPath.'/rsync-was-run';
        $this->bindRecordingRsync($marker);
        $disks = $this->swapRemoteDisks();

        Http::fake([
            self::ORIGIN.'/api/v1/delivery-target*' => Http::response($this->handshake()),
            '*' => Http::response(['data' => []]),
        ]);

        (new DeliverClassOutputs($class->id))->handle();

        $calls = File::get($marker);
        expect($calls)
            ->toContain('forge@origin.example:/mnt/photo-storage/proofs/26Test01/001')
            ->toContain('forge@origin.example:/mnt/photo-storage/web_images/26Test01/001')
            ->toContain('forge@origin.example:/mnt/photo-storage/highres_images/26Test01/001')
            ->not->toContain('staging')
            ->not->toContain('old-host');

        // The exists/mkdir checks (upload + advisory verifier) used the same
        // target as rsync.
        expect(array_values(array_unique($disks->roots)))->toBe([
            '/mnt/photo-storage/proofs',
            '/mnt/photo-storage/web_images',
            '/mnt/photo-storage/highres_images',
        ]);
    }

    public function test_a_saved_junk_path_from_an_older_install_is_not_a_destination(): void
    {
        // v1 stored "0" for an unset highres path.
        config(['proofgen.sftp.highres_images_path' => '0', 'proofgen.ferraraphoto.api_token' => null]);

        $target = app(DeliveryTargetResolver::class)->current($this->makeShow());

        expect($target->directory('highres_images'))->toBe('')
            ->and($target->directory('proofs'))->toBe('/staging/proofs/26Test01');
    }

    public function test_opening_a_show_learns_the_websites_destination_before_any_delivery(): void
    {
        Storage::fake('fullsize');
        Storage::fake('archive');
        Storage::disk('fullsize')->makeDirectory('26Test01/001');
        $this->makeShow();

        Http::fake([
            self::ORIGIN.'/api/v1/delivery-target*' => Http::response($this->handshake()),
            '*' => Http::response(['data' => []]),
        ]);

        Livewire::test(ShowViewComponent::class, ['show_id' => '26Test01'])
            ->assertSee('local settings')
            ->assertSee('/staging/proofs/26Test01')
            ->call('loadWebsiteShows')
            ->assertSee('from website')
            ->assertSee('/mnt/photo-storage/proofs/26Test01')
            ->assertDontSee('/staging/proofs/26Test01');
    }

    public function test_an_upload_check_never_runs_against_an_unconfirmed_destination(): void
    {
        Storage::fake('fullsize');
        Storage::fake('archive');
        Storage::disk('fullsize')->makeDirectory('26Test01/001');
        $this->makeShow();

        $marker = $this->tempPath.'/rsync-was-run';
        $this->bindRecordingRsync($marker);
        $this->fakeHandshake(['error' => ['code' => 'delivery_target_unavailable', 'message' => 'No usable profile.']], 503);

        Livewire::test(ShowViewComponent::class, ['show_id' => '26Test01'])
            ->call('checkProofAndWebImageUploads')
            ->assertSee('Upload check skipped');

        expect(File::exists($marker))->toBeFalse();
    }

    public function test_the_show_page_shows_the_stopped_destination_and_accepts_it(): void
    {
        Storage::fake('fullsize');
        Storage::fake('archive');
        // The panels only render once the show has a class directory.
        Storage::disk('fullsize')->makeDirectory('26Test01/001');

        $show = $this->makeShow();
        $class = $this->makeClass($show, '001');
        $this->fakeHandshake($this->handshake());
        $resolver = app(DeliveryTargetResolver::class);
        $resolver->refresh($show);
        $this->markUploaded($class);

        $this->fakeHandshakeSequence($this->handshake(root: '/mnt/new-volume'));
        try {
            $resolver->refresh($show->fresh());
        } catch (DeliveryTargetException) {
            // expected: leaves the pending destination for the operator
        }

        Livewire::test(ShowViewComponent::class, ['show_id' => '26Test01'])
            ->assertSuccessful()
            ->assertSee('from website')
            ->assertSee('/mnt/photo-storage/proofs/26Test01')
            ->assertSee('Uploads are stopped')
            ->assertSee('/mnt/new-volume/proofs/26Test01')
            ->call('acceptDeliveryTarget')
            ->assertDontSee('Uploads are stopped')
            ->assertSee('/mnt/new-volume/proofs/26Test01');

        expect($resolver->pending($show->fresh()))->toBeNull();
    }

    private function fakeHandshakeSequence(array $body, int $status = 200): void
    {
        // Http::fake() stubs accumulate and the first match wins, so a changed
        // answer needs a fresh factory.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeHandshake($body, $status);
    }

    /**
     * A fake rsync that appends its argv to $marker and reports nothing transferred.
     */
    private function bindRecordingRsync(string $marker): void
    {
        $path = $this->tempPath.'/rsync';
        File::put($path, "#!/bin/sh\necho \"$@\" >> ".escapeshellarg($marker)."\nexit 0\n");
        chmod($path, 0755);

        app()->instance(RsyncRunner::class, new RsyncRunner($path));
    }

    /**
     * Replace the SFTP disk a Gallery target would build with a local
     * throwaway disk, recording the roots that were asked for.
     */
    private function swapRemoteDisks(): DeliveryTargetDisks
    {
        $disks = new class($this->tempPath.'/remote') extends DeliveryTargetDisks
        {
            /** @var array<int, string> */
            public array $roots = [];

            public function __construct(private string $sandbox) {}

            protected function remoteDisk(DeliveryTarget $target, string $root): Filesystem
            {
                $this->roots[] = $root;

                return Storage::build(['driver' => 'local', 'root' => $this->sandbox.$root]);
            }
        };

        app()->instance(DeliveryTargetDisks::class, $disks);

        return $disks;
    }
}
