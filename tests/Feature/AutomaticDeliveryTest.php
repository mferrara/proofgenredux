<?php

use App\Jobs\Photo\GenerateThumbnails;
use App\Jobs\ShowClass\DeliverClassOutputs;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Livewire\ClassViewComponent;
use App\Livewire\ShowViewComponent;
use App\Models\Configuration;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\PathResolver;
use App\Services\PhotoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Automatic delivery gating and dispatch semantics.
 *
 * When a generation kind finishes for the whole class, PhotoService used to
 * dispatch the legacy per-kind rsync jobs directly. It now queues one unified
 * DeliverClassOutputs run — and only when config('proofgen.upload_proofs') is
 * enabled. Explicit operator upload actions are not gated.
 *
 * Isolation: every test writes synthetic GD images into a faked fullsize disk;
 * job dispatches are recorded by Bus::fake and never executed.
 */
class AutomaticDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
        Configuration::setConfig('upload_proofs', 'false', 'boolean');

        $this->tempPath = storage_path('app/delivery_gate_test_'.uniqid());
        File::makeDirectory($this->tempPath, 0755, true);

        config([
            'proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize',
            'proofgen.archive_home_dir' => $this->tempPath.'/archive',
            'proofgen.archive_enabled' => false,
            'proofgen.rename_files' => false,
            'proofgen.watermark_proofs' => false,
            'proofgen.upload_proofs' => false,
            'proofgen.thumbnails' => [
                'small' => ['suffix' => '_thm', 'width' => 400, 'height' => 600, 'quality' => 90, 'font_size' => 8, 'bg_size' => 16],
                'large' => ['suffix' => '_std', 'width' => 1024, 'height' => 1536, 'quality' => 90, 'font_size' => 20, 'bg_size' => 40],
            ],
            'proofgen.web_images' => ['suffix' => '_web', 'width' => 800, 'height' => 1200, 'quality' => 90],
            'proofgen.highres_images' => ['suffix' => '_highres', 'width' => 3000, 'height' => 3000, 'quality' => 95],
        ]);

        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        Storage::forgetDisk('fullsize');

        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));
        ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']));
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /**
     * Seed a Photo whose generated-at stamps mark the given kinds as done.
     * Kinds without a stamp are "still generating".
     */
    protected function seedPhoto(string $proofNumber, bool $proofsDone = true, bool $webDone = true, bool $highresDone = true, bool $withOriginal = false): Photo
    {
        $stamp = now()->subMinute();

        Photo::withoutEvents(fn () => Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber),
            'proofs_generated_at' => $proofsDone ? $stamp : null,
            'web_image_generated_at' => $webDone ? $stamp : null,
            'highres_image_generated_at' => $highresDone ? $stamp : null,
        ]));

        if ($withOriginal) {
            Storage::disk('fullsize')->put(
                'SHOW1/101/originals/'.$proofNumber.'.jpg',
                $this->syntheticJpeg()
            );
        }

        return Photo::find('SHOW1_101_'.$proofNumber);
    }

    protected function syntheticJpeg(): string
    {
        $gd = imagecreatetruecolor(1200, 800);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 120, 140, 160));
        ob_start();
        imagejpeg($gd, null, 85);
        imagedestroy($gd);

        return (string) ob_get_clean();
    }

    public function test_auto_off_prevents_delivery_after_proofs_finish(): void
    {
        Bus::fake();
        // Simulate a worker that booted with uploads ON before the saved switch went OFF.
        config(['proofgen.upload_proofs' => true]);
        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        app(PhotoService::class)->generateThumbnails($photo->id, $photo->proofs_path);

        Bus::assertNothingDispatched();
    }

    public function test_auto_off_prevents_delivery_after_web_images_finish(): void
    {
        Bus::fake();
        // Simulate a worker that booted with uploads ON before the saved switch went OFF.
        config(['proofgen.upload_proofs' => true]);
        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        $path = (new PathResolver)->getWebImagesPath('SHOW1', '101');
        app(PhotoService::class)->generateWebImage($photo->id, $path);

        Bus::assertNothingDispatched();
    }

    public function test_auto_off_prevents_delivery_after_highres_images_finish(): void
    {
        Bus::fake();
        // Simulate a worker that booted with uploads ON before the saved switch went OFF.
        config(['proofgen.upload_proofs' => true]);
        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        $path = (new PathResolver)->getHighresImagesPath('SHOW1', '101');
        app(PhotoService::class)->generateHighresImage($photo->id, $path);

        Bus::assertNothingDispatched();
    }

    public function test_auto_off_also_gates_the_queued_generation_job_entrypoint(): void
    {
        Bus::fake();
        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        (new GenerateThumbnails($photo->id, $photo->proofs_path))->handle();

        Bus::assertNothingDispatched();

        // The same job entrypoint queues delivery once the switch is on.
        config(['proofgen.upload_proofs' => true]);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');

        (new GenerateThumbnails($photo->id, $photo->proofs_path))->handle();

        Bus::assertDispatched(DeliverClassOutputs::class);
    }

    public function test_auto_on_queues_unified_delivery_for_each_finished_kind(): void
    {
        Bus::fake();
        config(['proofgen.upload_proofs' => true]);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');

        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        $service = app(PhotoService::class);

        $service->generateThumbnails($photo->id, $photo->proofs_path);
        $service->generateWebImage($photo->id, (new PathResolver)->getWebImagesPath('SHOW1', '101'));
        $service->generateHighresImage($photo->id, (new PathResolver)->getHighresImagesPath('SHOW1', '101'));

        // One delivery per finished kind, all scoped to the class, none of the
        // legacy per-kind rsync jobs dispatched directly anymore.
        Bus::assertDispatched(DeliverClassOutputs::class, 3);
        Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->classId === 'SHOW1_101');
        Bus::assertNotDispatched(UploadProofs::class);
        Bus::assertNotDispatched(UploadWebImages::class);
        Bus::assertNotDispatched(UploadHighresImages::class);
    }

    public function test_no_delivery_until_the_class_finishes_generating_the_kind(): void
    {
        Bus::fake();
        config(['proofgen.upload_proofs' => true]);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');

        // First photo still lacks proofs: the class is not done proofing.
        $this->seedPhoto('SHOW1_00001', proofsDone: false);
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        app(PhotoService::class)->generateThumbnails($photo->id, $photo->proofs_path);

        Bus::assertNotDispatched(DeliverClassOutputs::class);
    }

    public function test_check_for_upload_false_skips_delivery_even_when_auto_is_on(): void
    {
        Bus::fake();
        config(['proofgen.upload_proofs' => true]);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');

        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        app(PhotoService::class)->generateThumbnails($photo->id, $photo->proofs_path, false);
        app(PhotoService::class)->generateWebImage($photo->id, (new PathResolver)->getWebImagesPath('SHOW1', '101'), false);
        app(PhotoService::class)->generateHighresImage($photo->id, (new PathResolver)->getHighresImagesPath('SHOW1', '101'), false);

        Bus::assertNothingDispatched();
    }

    #[DataProvider('completionOrders')]
    public function test_every_completion_order_preserves_later_delivery_requests(array $order): void
    {
        Bus::fake();
        Configuration::setConfig('upload_proofs', 'true', 'boolean');
        $photo = $this->seedPhoto('SHOW1_00001', false, false, false, true);
        $paths = new PathResolver;
        $destinations = [
            'generateThumbnails' => $photo->proofs_path,
            'generateWebImage' => $paths->getWebImagesPath('SHOW1', '101'),
            'generateHighresImage' => $paths->getHighresImagesPath('SHOW1', '101'),
        ];
        foreach ($order as $index => $method) {
            app(PhotoService::class)->$method($photo->id, $destinations[$method]);
            Bus::assertDispatched(DeliverClassOutputs::class, $index + 1);
        }
        Bus::assertDispatched(DeliverClassOutputs::class, fn ($job) => $job->automatic);
    }

    public static function completionOrders(): array
    {
        $p = 'generateThumbnails';
        $w = 'generateWebImage';
        $h = 'generateHighresImage';

        return [[[$p, $w, $h]], [[$p, $h, $w]], [[$w, $p, $h]],
            [[$w, $h, $p]], [[$h, $p, $w]], [[$h, $w, $p]]];
    }

    public function test_each_kind_completion_queues_its_own_delivery_without_uniqueness_drops(): void
    {
        Bus::fake();
        config(['proofgen.upload_proofs' => true]);
        Configuration::setConfig('upload_proofs', 'true', 'boolean');

        $this->seedPhoto('SHOW1_00001');
        $photo = $this->seedPhoto('SHOW1_00002', withOriginal: true);

        app(PhotoService::class)->generateThumbnails($photo->id, $photo->proofs_path);
        Bus::assertDispatched(DeliverClassOutputs::class, 1);

        // A later completion queues another delivery; the job is deliberately
        // not ShouldBeUnique so this second run is not swallowed.
        $photo = $photo->fresh();
        app(PhotoService::class)->generateWebImage($photo->id, (new PathResolver)->getWebImagesPath('SHOW1', '101'));
        Bus::assertDispatched(DeliverClassOutputs::class, 2);

        expect(in_array(ShouldBeUnique::class, class_implements(DeliverClassOutputs::class), true))->toBeFalse();
    }

    public function test_explicit_class_upload_still_dispatches_with_auto_off(): void
    {
        Bus::fake();
        config(['proofgen.upload_proofs' => false]);
        Configuration::setConfig('upload_proofs', 'false', 'boolean');
        Storage::fake('archive');

        // Proof files must exist on disk so the boot-time sync does not revert
        // the generated stamps on the pending photo.
        $this->seedPhoto('SHOW1_00001');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001_thm.jpg', 'thm');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'std');

        Livewire::test(ClassViewComponent::class, ['show' => 'SHOW1', 'class' => '101'])
            ->call('uploadPendingProofsAndWebImages');

        Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->classId === 'SHOW1_101');
    }

    public function test_explicit_show_upload_dispatches_per_class_with_auto_off(): void
    {
        Bus::fake();
        config(['proofgen.upload_proofs' => false]);
        Configuration::setConfig('upload_proofs', 'false', 'boolean');
        Storage::fake('archive');

        ShowClass::withoutEvents(fn () => ShowClass::create(['id' => 'SHOW1_102', 'show_id' => 'SHOW1', 'name' => '102']));

        // Class 101 has a pending proof upload; class 102 has nothing pending.
        $this->seedPhoto('SHOW1_00001');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001_thm.jpg', 'thm');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/SHOW1_00001_std.jpg', 'std');

        Livewire::test(ShowViewComponent::class, ['show_id' => 'SHOW1'])
            ->call('uploadPendingProofsAndWebImages');

        // One delivery per kind, each on its own queue, so proofs of every
        // class go up before any class's web or highres images.
        Bus::assertDispatched(DeliverClassOutputs::class, 3);
        Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->classId === 'SHOW1_101' && $job->kinds === ['proofs'] && $job->queue === config('proofgen.uploads.queue'));
        Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->kinds === ['web'] && $job->queue === config('proofgen.uploads.web_queue'));
        Bus::assertDispatched(fn (DeliverClassOutputs $job) => $job->kinds === ['highres'] && $job->queue === config('proofgen.uploads.highres_queue'));
    }
}
