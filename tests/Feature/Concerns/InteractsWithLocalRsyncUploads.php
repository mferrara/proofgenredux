<?php

namespace Tests\Feature\Concerns;

use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use App\Services\Transport\RsyncRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Shared local-only upload harness: the `local` transport driver pointed at a
 * throwaway temp tree, plus helper seeding for proofs / web / highres
 * derivatives and scripted fake rsync binaries.
 *
 * No network, no SFTP, no production paths.
 */
trait InteractsWithLocalRsyncUploads
{
    protected string $tempPath;

    protected Show $show;

    protected ShowClass $class;

    protected function setUpLocalRsyncUploads(): void
    {
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

    protected function tearDownLocalRsyncUploads(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
    }

    /**
     * Seed a Photo with proofs (small + large thumbnails) on the local fullsize disk
     * and the matching DB row marked as having proofs generated but not yet uploaded.
     */
    protected function seedPhotoWithProofs(string $proofNumber): Photo
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

    protected function seedPhotoWithWebImage(string $proofNumber): Photo
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

    protected function seedPhotoWithHighresImage(string $proofNumber): Photo
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

    /**
     * Write a fake `rsync` executable that replaces the real binary for one test.
     */
    protected function bindFakeRsync(string $script): string
    {
        $dir = $this->tempPath.'/fake-bin';
        File::makeDirectory($dir, 0755, true);

        $path = $dir.'/rsync';
        File::put($path, $script);
        chmod($path, 0755);

        app()->instance(RsyncRunner::class, new RsyncRunner($path));

        return $path;
    }

    /**
     * Drop a fake runner binding so the real rsync binary is used again.
     */
    protected function useRealRsync(): void
    {
        app()->forgetInstance(RsyncRunner::class);
    }
}
