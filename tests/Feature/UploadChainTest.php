<?php

namespace Tests\Feature;

use App\Jobs\Show\UploadShowHighresImages;
use App\Jobs\Show\UploadShowProofs;
use App\Jobs\Show\UploadShowWebImages;
use App\Jobs\ShowClass\UploadHighresImages;
use App\Jobs\ShowClass\UploadProofs;
use App\Jobs\ShowClass\UploadWebImages;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end exercise of the upload-chain (proofs/web/highres) using the
 * `local` transport driver and real `rsync` against local directories.
 *
 * The local driver rewrites the `remote_*` Storage disks to plain local
 * filesystem disks rooted under each test's tempPath, and the rsync command
 * builder produces a plain `rsync -avz src/ dest` (no SSH).
 *
 * Mirrors the disk-rewrite pattern from ArchiveBackupTest.
 */
class UploadChainTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    private Show $show;

    private ShowClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/opt/homebrew/bin/rsync') && ! is_executable('/usr/bin/rsync')) {
            $this->markTestSkipped('rsync binary not found; required for local-driver upload tests.');
        }

        config(['testing.skip_file_operations' => true]);

        $this->tempPath = storage_path('app/upload_chain_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/fullsize', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-proofs', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-web', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-highres', 0755, true);

        // Point both the proofgen.* paths and the underlying Storage disks at
        // the same tempPath subdirectories. We bypass ConfigurationServiceProvider's
        // applyTransportDriver() by writing the disk configs directly.
        config(['proofgen.fullsize_home_dir' => $this->tempPath.'/fullsize']);
        config(['proofgen.sftp.driver' => 'local']);
        config(['proofgen.sftp.path' => $this->tempPath.'/remote-proofs']);
        config(['proofgen.sftp.web_images_path' => $this->tempPath.'/remote-web']);
        config(['proofgen.sftp.highres_images_path' => $this->tempPath.'/remote-highres']);

        // Ensure thumbnail/web/highres suffix config is sane for the test
        // even if the testing env hasn't loaded the .env values.
        $thumbnails = config('proofgen.thumbnails');
        if (empty($thumbnails['small']['suffix'])) {
            config(['proofgen.thumbnails.small.suffix' => '_thm']);
        }
        if (empty($thumbnails['large']['suffix'])) {
            config(['proofgen.thumbnails.large.suffix' => '_std']);
        }
        if (empty(config('proofgen.web_images.suffix'))) {
            config(['proofgen.web_images.suffix' => '_web']);
        }
        if (empty(config('proofgen.highres_images.suffix'))) {
            config(['proofgen.highres_images.suffix' => '_highres']);
        }

        config(['filesystems.disks.fullsize' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/fullsize',
            'throw' => true,
        ]]);
        config(['filesystems.disks.remote_proofs' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/remote-proofs',
            'throw' => false,
        ]]);
        config(['filesystems.disks.remote_web_images' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/remote-web',
            'throw' => false,
        ]]);
        config(['filesystems.disks.remote_highres_images' => [
            'driver' => 'local',
            'root' => $this->tempPath.'/remote-highres',
            'throw' => false,
        ]]);

        Storage::forgetDisk('fullsize');
        Storage::forgetDisk('remote_proofs');
        Storage::forgetDisk('remote_web_images');
        Storage::forgetDisk('remote_highres_images');

        Show::withoutEvents(function () {
            $this->show = Show::create([
                'id' => 'SHOW1',
                'name' => 'SHOW1',
            ]);
        });

        ShowClass::withoutEvents(function () {
            $this->class = ShowClass::create([
                'id' => 'SHOW1_101',
                'show_id' => 'SHOW1',
                'name' => '101',
            ]);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /**
     * Seed a Photo with proofs (small + large thumbnails) on the local fullsize disk
     * and the matching DB row marked as having proofs generated but not yet uploaded.
     */
    private function seedPhotoWithProofs(string $proofNumber): Photo
    {
        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber.'_proof'),
            'proofs_generated_at' => Carbon::now()->subMinute(),
        ]);

        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');

        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$smallSuffix.'.jpg', 'thm bytes for '.$proofNumber);
        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$largeSuffix.'.jpg', 'std bytes for '.$proofNumber);

        return $photo->fresh();
    }

    private function seedPhotoWithWebImage(string $proofNumber): Photo
    {
        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber.'_web'),
            'web_image_generated_at' => Carbon::now()->subMinute(),
        ]);

        $suffix = config('proofgen.web_images.suffix');
        Storage::disk('fullsize')->put('web_images/SHOW1/101/'.$proofNumber.$suffix.'.jpg', 'web bytes for '.$proofNumber);

        return $photo->fresh();
    }

    private function seedPhotoWithHighresImage(string $proofNumber): Photo
    {
        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber.'_highres'),
            'highres_image_generated_at' => Carbon::now()->subMinute(),
        ]);

        $suffix = config('proofgen.highres_images.suffix');
        Storage::disk('fullsize')->put('highres_images/SHOW1/101/'.$proofNumber.$suffix.'.jpg', 'highres bytes for '.$proofNumber);

        return $photo->fresh();
    }

    public function test_per_class_proofs_upload_pushes_files_via_local_rsync_and_marks_photos_uploaded(): void
    {
        $proofNumbers = ['SHOW1_00001', 'SHOW1_00002', 'SHOW1_00003'];
        foreach ($proofNumbers as $pn) {
            $this->seedPhotoWithProofs($pn);
        }

        UploadProofs::dispatchSync('SHOW1', '101');

        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');

        foreach ($proofNumbers as $pn) {
            $this->assertTrue(
                Storage::disk('remote_proofs')->exists('SHOW1/101/'.$pn.$smallSuffix.'.jpg'),
                "missing small proof for {$pn} on remote disk"
            );
            $this->assertTrue(
                Storage::disk('remote_proofs')->exists('SHOW1/101/'.$pn.$largeSuffix.'.jpg'),
                "missing large proof for {$pn} on remote disk"
            );

            $photo = Photo::find('SHOW1_101_'.$pn);
            $this->assertNotNull($photo->proofs_uploaded_at, "proofs_uploaded_at not set for {$pn}");
        }
    }

    public function test_per_class_web_images_upload_pushes_files_and_marks_photos_uploaded(): void
    {
        $proofNumbers = ['SHOW1_00010', 'SHOW1_00011'];
        foreach ($proofNumbers as $pn) {
            $this->seedPhotoWithWebImage($pn);
        }

        UploadWebImages::dispatchSync('SHOW1', '101');

        $suffix = config('proofgen.web_images.suffix');

        foreach ($proofNumbers as $pn) {
            $this->assertTrue(
                Storage::disk('remote_web_images')->exists('SHOW1/101/'.$pn.$suffix.'.jpg'),
                "missing web image for {$pn} on remote disk"
            );

            $photo = Photo::find('SHOW1_101_'.$pn);
            $this->assertNotNull($photo->web_image_uploaded_at, "web_image_uploaded_at not set for {$pn}");
        }
    }

    public function test_per_class_highres_upload_pushes_files_and_marks_photos_uploaded(): void
    {
        $proofNumbers = ['SHOW1_00020', 'SHOW1_00021'];
        foreach ($proofNumbers as $pn) {
            $this->seedPhotoWithHighresImage($pn);
        }

        UploadHighresImages::dispatchSync('SHOW1', '101');

        $suffix = config('proofgen.highres_images.suffix');

        foreach ($proofNumbers as $pn) {
            $this->assertTrue(
                Storage::disk('remote_highres_images')->exists('SHOW1/101/'.$pn.$suffix.'.jpg'),
                "missing highres image for {$pn} on remote disk"
            );

            $photo = Photo::find('SHOW1_101_'.$pn);
            $this->assertNotNull($photo->highres_image_uploaded_at, "highres_image_uploaded_at not set for {$pn}");
        }
    }

    public function test_show_level_chain_uploads_proofs_then_web_then_highres(): void
    {
        // Seed one photo that has ALL three derivative types ready.
        $proofNumber = 'SHOW1_00030';

        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber),
            'proofs_generated_at' => Carbon::now()->subMinute(),
            'web_image_generated_at' => Carbon::now()->subMinute(),
            'highres_image_generated_at' => Carbon::now()->subMinute(),
        ]);

        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');
        $webSuffix = config('proofgen.web_images.suffix');
        $highresSuffix = config('proofgen.highres_images.suffix');

        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$smallSuffix.'.jpg', 'thm');
        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$largeSuffix.'.jpg', 'std');
        Storage::disk('fullsize')->put('web_images/SHOW1/101/'.$proofNumber.$webSuffix.'.jpg', 'web');
        Storage::disk('fullsize')->put('highres_images/SHOW1/101/'.$proofNumber.$highresSuffix.'.jpg', 'highres');

        // Mirror ShowViewComponent::uploadPendingProofsAndWebImages() — this is
        // the actual chain entry point exposed in the UI.
        Bus::chain([
            new UploadShowProofs('SHOW1'),
            new UploadShowWebImages('SHOW1'),
            new UploadShowHighresImages('SHOW1'),
        ])->dispatch();

        // All three remote trees should now contain the file at SHOW1/101/...
        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$smallSuffix.'.jpg'));
        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$largeSuffix.'.jpg'));
        $this->assertTrue(Storage::disk('remote_web_images')->exists('SHOW1/101/'.$proofNumber.$webSuffix.'.jpg'));
        $this->assertTrue(Storage::disk('remote_highres_images')->exists('SHOW1/101/'.$proofNumber.$highresSuffix.'.jpg'));

        $photo = $photo->fresh();
        $this->assertNotNull($photo->proofs_uploaded_at);
        $this->assertNotNull($photo->web_image_uploaded_at);
        $this->assertNotNull($photo->highres_image_uploaded_at);

        // Order check: proofs must have been timestamped at-or-before the others
        // (the chain ran them in order; on the sync queue all run within the same
        // dispatch call and proofs are first).
        $this->assertLessThanOrEqual(
            $photo->web_image_uploaded_at->getTimestamp(),
            $photo->proofs_uploaded_at->getTimestamp(),
            'proofs should have uploaded at-or-before web images in the chain'
        );
        $this->assertLessThanOrEqual(
            $photo->highres_image_uploaded_at->getTimestamp(),
            $photo->web_image_uploaded_at->getTimestamp(),
            'web images should have uploaded at-or-before highres in the chain'
        );
    }

    public function test_idempotent_re_upload_keeps_photos_marked_uploaded_and_does_not_break(): void
    {
        $proofNumbers = ['SHOW1_00040', 'SHOW1_00041'];
        foreach ($proofNumbers as $pn) {
            $this->seedPhotoWithProofs($pn);
        }

        // First upload — populates remote and marks photos.
        UploadProofs::dispatchSync('SHOW1', '101');

        $firstUploadAt = [];
        foreach ($proofNumbers as $pn) {
            $photo = Photo::find('SHOW1_101_'.$pn);
            $this->assertNotNull($photo->proofs_uploaded_at);
            $firstUploadAt[$pn] = $photo->proofs_uploaded_at;
        }

        // Capture remote mtimes so we can confirm rsync didn't re-transfer files.
        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $remoteRoot = $this->tempPath.'/remote-proofs/SHOW1/101';
        $beforeMtimes = [];
        foreach ($proofNumbers as $pn) {
            $beforeMtimes[$pn] = filemtime($remoteRoot.'/'.$pn.$smallSuffix.'.jpg');
        }

        // rsync's mtime comparison has 1-second granularity; sleep so a re-copy
        // would be observable.
        sleep(2);

        // Second upload — should be a no-op transfer.
        UploadProofs::dispatchSync('SHOW1', '101');

        clearstatcache();
        foreach ($proofNumbers as $pn) {
            $afterMtime = filemtime($remoteRoot.'/'.$pn.$smallSuffix.'.jpg');
            $this->assertSame(
                $beforeMtimes[$pn],
                $afterMtime,
                "rsync re-transferred {$pn} on second run; expected idempotent skip"
            );

            $photo = Photo::find('SHOW1_101_'.$pn);
            $this->assertNotNull(
                $photo->proofs_uploaded_at,
                "proofs_uploaded_at regressed to null for {$pn} after re-upload"
            );
        }
    }

    public function test_partial_upload_only_pushes_photos_with_generated_derivatives(): void
    {
        // Two photos with proofs generated, one photo WITHOUT proofs generated.
        $generated = ['SHOW1_00050', 'SHOW1_00051'];
        foreach ($generated as $pn) {
            $this->seedPhotoWithProofs($pn);
        }

        $missingProof = 'SHOW1_00052';
        Photo::create([
            'id' => 'SHOW1_101_'.$missingProof,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $missingProof,
            'file_type' => 'jpg',
            'sha1' => sha1($missingProof),
            // no proofs_generated_at, no local files
        ]);

        UploadProofs::dispatchSync('SHOW1', '101');

        $smallSuffix = config('proofgen.thumbnails.small.suffix');
        $largeSuffix = config('proofgen.thumbnails.large.suffix');

        foreach ($generated as $pn) {
            $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$pn.$smallSuffix.'.jpg'));
            $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$pn.$largeSuffix.'.jpg'));

            $photo = Photo::find('SHOW1_101_'.$pn);
            $this->assertNotNull($photo->proofs_uploaded_at);
        }

        // Photo without local proofs should NOT appear on the remote and should
        // remain unmarked.
        $this->assertFalse(
            Storage::disk('remote_proofs')->exists('SHOW1/101/'.$missingProof.$smallSuffix.'.jpg')
        );
        $this->assertFalse(
            Storage::disk('remote_proofs')->exists('SHOW1/101/'.$missingProof.$largeSuffix.'.jpg')
        );

        $missingPhoto = Photo::find('SHOW1_101_'.$missingProof);
        $this->assertNull($missingPhoto->proofs_uploaded_at);
        $this->assertNull($missingPhoto->proofs_generated_at);
    }

    /**
     * Regression: upload parsing previously matched on $this->show->name. If an
     * operator renamed a Show (display label != id) while files on disk and proof
     * numbers used the id, uploads silently stopped tracking. Switching to id
     * fixes this; verify by setting name to something that doesn't match the
     * filename prefix.
     */
    public function test_upload_tracking_works_when_show_display_name_differs_from_id(): void
    {
        $this->show->name = 'Buck Show 2024';
        $this->show->saveQuietly();

        $this->seedPhotoWithProofs('SHOW1_00001');
        $this->seedPhotoWithProofs('SHOW1_00002');

        \App\Jobs\ShowClass\UploadProofs::dispatchSync($this->show->id, $this->class->name);

        foreach (['SHOW1_00001', 'SHOW1_00002'] as $proofNumber) {
            $photo = Photo::find('SHOW1_101_'.$proofNumber)->fresh();
            $this->assertNotNull(
                $photo->proofs_uploaded_at,
                'proofs_uploaded_at should be set even when show.name diverges from show.id'
            );
        }
    }
}
