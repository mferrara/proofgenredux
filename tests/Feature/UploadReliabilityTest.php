<?php

namespace Tests\Feature;

use App\Jobs\Show\UploadShowHighresImages;
use App\Jobs\Show\UploadShowProofs;
use App\Jobs\Show\UploadShowWebImages;
use App\Jobs\ShowClass\PushPhotoMetadata;
use App\Jobs\ShowClass\UploadDerivedFiles;
use App\Models\Photo;
use App\Models\StorageProfile;
use App\Services\Ferraraphoto\FerraraphotoApiClient;
use App\Services\Transport\RsyncFailedException;
use App\Services\Transport\RsyncRunner;
use App\Services\Transport\UploadConfigurationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithLocalRsyncUploads;
use Tests\TestCase;
use Throwable;

/**
 * Behavioral regressions for the upload evidence rules:
 *
 *  - a non-zero rsync exit (even with partial itemized stdout) throws and
 *    leaves upload timestamps untouched,
 *  - a successful real sync stamps complete local derivatives (including
 *    already-up-to-date files, so no-op retries reconcile missing stamps),
 *  - a dry-run never stamps and never invents timestamps for absent files,
 *  - proofs are only stamped when every configured suffix exists locally,
 *  - show slug overrides (including spaces/apostrophes) drive the remote path
 *    while the local path keeps using the show id.
 */
class UploadReliabilityTest extends TestCase
{
    use InteractsWithLocalRsyncUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLocalRsyncUploads();
    }

    protected function tearDown(): void
    {
        $this->tearDownLocalRsyncUploads();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, bool, string}>
     */
    public static function uploadSeamProvider(): array
    {
        $cases = [];

        foreach (['class', 'show'] as $scope) {
            foreach (['proofs', 'web_images', 'highres_images'] as $type) {
                foreach ([false, true] as $dryRun) {
                    foreach (['jpg', 'jpeg'] as $originalExtension) {
                        $cases[$scope.'/'.$type.'/'.($dryRun ? 'dry' : 'real').'/'.$originalExtension] = [$scope, $type, $dryRun, $originalExtension];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('uploadSeamProvider')]
    public function test_upload_seam_evidence_matrix(string $scope, string $type, bool $dryRun, string $originalExtension): void
    {
        $proofNumber = 'SHOW1_00010';
        $photo = $this->seedPhotoWithAllDerivatives($proofNumber);
        // Derivative generators always write .jpg, even for .jpeg originals.
        $photo->update(['file_type' => $originalExtension]);

        $owner = $scope === 'class' ? $this->class : $this->show;

        $result = $owner->{$this->seamMethod($type, $dryRun)}();

        $column = $this->uploadedColumn($type);
        $pendingRelativePath = $this->localRelativePathFor($type, $proofNumber);

        if ($dryRun) {
            $this->assertNotEmpty($result, "{$scope}/{$type} dry run should report pending files");
            $this->assertContains($pendingRelativePath, $result, "{$scope}/{$type} dry run should name the local file");
            $this->assertNull($photo->fresh()->{$column});
        } else {
            $this->assertNotEmpty($result, "{$scope}/{$type} real run should report synced files");
            $this->assertNotNull($photo->fresh()->{$column}, "{$scope}/{$type} real run should stamp the upload");

            foreach ($this->remotePathsFor($type, $proofNumber) as $remotePath) {
                $this->assertTrue(
                    Storage::disk($this->remoteDisk($type))->exists($remotePath),
                    "missing remote file {$remotePath} for {$scope}/{$type}"
                );
            }
        }
    }

    public function test_forced_rsync_failure_with_partial_stdout_throws_and_keeps_timestamps(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00001');

        $this->bindFakeRsync($this->failingRsyncScript([
            '101/SHOW1_00001'.$this->smallSuffix().'.jpg',
            '101/SHOW1_00001'.$this->largeSuffix().'.jpg',
        ]));

        try {
            $this->class->proofUploads();
            $this->fail('Expected RsyncFailedException from a non-zero rsync exit.');
        } catch (RsyncFailedException $e) {
            $this->assertStringContainsString('exit code 23', $e->getMessage());
            $this->assertStringContainsString('simulated connection drop', $e->getMessage());
        }

        $this->assertNull($photo->fresh()->proofs_uploaded_at);

        $this->assertFalse(Storage::disk('remote_proofs')->exists('SHOW1/101/SHOW1_00001'.$this->smallSuffix().'.jpg'));
        $this->assertFalse(Storage::disk('remote_proofs')->exists('SHOW1/101/SHOW1_00001'.$this->largeSuffix().'.jpg'));
    }

    public function test_show_level_forced_failure_throws_and_keeps_timestamps(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00002');

        $this->bindFakeRsync($this->failingRsyncScript([
            '101/SHOW1_00002'.$this->smallSuffix().'.jpg',
        ]));

        try {
            $this->show->proofUploads();
            $this->fail('Expected RsyncFailedException from a non-zero rsync exit.');
        } catch (RsyncFailedException) {
            $this->assertNull($photo->fresh()->proofs_uploaded_at);
        }
    }

    public function test_successful_no_op_retry_stamps_missing_timestamps(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00003');

        // First real sync pushes byte-identical copies with preserved mtimes.
        $this->class->proofUploads();
        $photo->refresh();
        $photo->proofs_uploaded_at = null;
        $photo->save();

        // The retry transfers nothing; only rsync's `-ii` unchanged entries
        // prove the remote already holds both variants.
        $result = $this->class->proofUploads();

        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);
        $this->assertArrayHasKey('SHOW1_00003', $result);
        $this->assertCount(2, $result['SHOW1_00003']);
    }

    public function test_partial_failure_then_successful_retry_reconciles_stamps(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00004');

        // First attempt reports one file then dies — no stamp may be applied.
        $this->bindFakeRsync($this->failingRsyncScript([
            '101/SHOW1_00004'.$this->smallSuffix().'.jpg',
        ]));

        try {
            $this->class->proofUploads();
            $this->fail('Expected RsyncFailedException from a non-zero rsync exit.');
        } catch (RsyncFailedException) {
            // expected
        }

        $this->assertNull($photo->fresh()->proofs_uploaded_at);

        // Simulate the partial remote state a dropped connection can leave
        // behind, then retry with the real rsync.
        Storage::disk('remote_proofs')->put(
            'SHOW1/101/SHOW1_00004'.$this->smallSuffix().'.jpg',
            'thm bytes for SHOW1_00004'
        );
        $this->useRealRsync();

        $this->class->proofUploads();

        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);
        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/SHOW1_00004'.$this->smallSuffix().'.jpg'));
        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/SHOW1_00004'.$this->largeSuffix().'.jpg'));
    }

    public function test_missing_proof_variant_never_stamps_after_successful_sync(): void
    {
        $proofNumber = 'SHOW1_00005';

        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber),
            'proofs_generated_at' => Carbon::now()->subMinute(),
        ]);

        // Only the small variant exists locally.
        Storage::disk('fullsize')->put(
            'proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg',
            'thm bytes for '.$proofNumber
        );

        $this->class->proofUploads();
        $this->assertNull($photo->fresh()->proofs_uploaded_at);

        // Same rule at show scope: the class sync must not have stamped either.
        $this->show->proofUploads();
        $this->assertNull($photo->fresh()->proofs_uploaded_at);

        $this->assertTrue(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg'));
        $this->assertFalse(Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$this->largeSuffix().'.jpg'));
    }

    public function test_pending_check_does_not_stamp_absent_files(): void
    {
        $proofNumber = 'SHOW1_00006';

        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber),
            'proofs_generated_at' => Carbon::now()->subMinute(),
        ]);

        $this->assertSame([], $this->class->pendingProofUploads());
        $this->assertNull($photo->fresh()->proofs_uploaded_at);

        $this->assertSame([], $this->show->pendingProofUploads());
        $this->assertNull($photo->fresh()->proofs_uploaded_at);
    }

    public function test_pending_check_reports_pending_files_and_clears_stale_stamps(): void
    {
        $proofNumber = 'SHOW1_00007';
        $photo = $this->seedPhotoWithProofs($proofNumber);

        // Stale stamp that the dry-run must correct: the files are not remote yet.
        $photo->proofs_uploaded_at = Carbon::now();
        $photo->save();

        $pending = $this->class->pendingProofUploads();

        $this->assertCount(2, $pending);
        $this->assertContains('proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg', $pending);
        $this->assertNull($photo->fresh()->proofs_uploaded_at);
    }

    public function test_successful_dry_run_alone_never_stamps(): void
    {
        $proofNumber = 'SHOW1_00008';
        $photo = $this->seedPhotoWithProofs($proofNumber);

        // Real run first so the remote matches byte-for-byte.
        $this->class->proofUploads();
        $photo->refresh();
        $photo->proofs_uploaded_at = null;
        $photo->save();

        $pending = $this->class->pendingProofUploads();

        $this->assertSame([], $pending, 'up-to-date remote should report no pending files');
        $this->assertNull($photo->fresh()->proofs_uploaded_at, 'a clean dry-run is not remote-success evidence');
    }

    public function test_upload_paths_honor_slug_override_with_spaces_and_apostrophes(): void
    {
        $proofNumber = 'SHOW1_00009';

        $this->show->ferraraphoto_show_slug = "buck's show 24";
        $this->show->save();
        $this->class = $this->class->fresh();

        $photo = $this->seedPhotoWithProofs($proofNumber);

        $this->class->proofUploads();

        $this->assertTrue(
            Storage::disk('remote_proofs')->exists("buck's show 24/101/".$proofNumber.$this->smallSuffix().'.jpg'),
            'remote proofs must use the ferraraphoto slug, not the local show id'
        );
        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);

        // The local source tree still lives under the proofgen show id.
        $this->assertTrue(
            Storage::disk('fullsize')->exists('proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg')
        );
    }

    public function test_show_level_uploads_honor_slug_override(): void
    {
        $proofNumber = 'SHOW1_00011';

        $this->show->ferraraphoto_show_slug = 'buck-show-2024';
        $this->show->save();
        $this->class = $this->class->fresh();

        $photo = $this->seedPhotoWithProofs($proofNumber);

        $this->show->proofUploads();

        $this->assertTrue(
            Storage::disk('remote_proofs')->exists('buck-show-2024/101/'.$proofNumber.$this->smallSuffix().'.jpg')
        );
        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);
    }

    public function test_local_transport_handles_destination_directories_with_spaces_and_apostrophes(): void
    {
        $proofNumber = 'SHOW1_00012';
        $destination = $this->tempPath."/remote proof's";

        File::makeDirectory($destination, 0755, true);
        config(['proofgen.sftp.path' => $destination]);
        config(['filesystems.disks.remote_proofs' => [
            'driver' => 'local',
            'root' => $destination,
            'throw' => false,
        ]]);
        Storage::forgetDisk('remote_proofs');

        $photo = $this->seedPhotoWithProofs($proofNumber);

        $this->class->proofUploads();

        $this->assertTrue(
            Storage::disk('remote_proofs')->exists('SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg')
        );
        $this->assertNotNull($photo->fresh()->proofs_uploaded_at);
    }

    public function test_failed_proofs_job_stops_show_chain_before_paid_images(): void
    {
        $proofNumber = 'SHOW1_00013';
        $photo = $this->seedPhotoWithAllDerivatives($proofNumber);

        // Fail only the proofs tree so a continuing chain would visibly copy the
        // web/highres files.
        $this->bindFakeRsync($this->failingProofsOnlyRsyncScript($proofNumber));

        try {
            Bus::chain([
                new UploadShowProofs('SHOW1'),
                new UploadShowWebImages('SHOW1'),
                new UploadShowHighresImages('SHOW1'),
            ])->dispatch();
        } catch (Throwable) {
            // The sync queue may surface or swallow the job failure; the chain
            // must not have reached the later jobs either way.
        }

        $this->assertNull($photo->fresh()->proofs_uploaded_at);
        $this->assertNull($photo->fresh()->web_image_uploaded_at);
        $this->assertNull($photo->fresh()->highres_image_uploaded_at);
        $this->assertFalse(Storage::disk('remote_web_images')->exists('SHOW1/101/'.$proofNumber.$this->webSuffix().'.jpg'));
        $this->assertFalse(Storage::disk('remote_highres_images')->exists('SHOW1/101/'.$proofNumber.$this->highresSuffix().'.jpg'));
    }

    public function test_legacy_derived_upload_throws_so_metadata_jobs_are_not_reached(): void
    {
        $proofNumber = 'SHOW1_00014';
        $this->seedPhotoWithAllDerivatives($proofNumber);

        $profile = StorageProfile::findOrFail(StorageProfile::LEGACY_LOCAL_ID);
        $this->show->storage_profile_id = $profile->id;
        $this->show->save();

        $this->bindFakeRsync($this->failingProofsOnlyRsyncScript($proofNumber));

        // UploadDerivedFiles dispatches the legacy upload jobs synchronously; a
        // thrown failure must escape so a Bus::chain stops before metadata push.
        $this->expectException(RsyncFailedException::class);

        UploadDerivedFiles::dispatchSync('SHOW1_101');
    }

    public function test_derivative_appearing_during_runner_is_not_stamped(): void
    {
        $complete = $this->seedPhotoWithProofs('SHOW1_00030');

        $lateNumber = 'SHOW1_00031';
        $late = Photo::create([
            'id' => 'SHOW1_101_'.$lateNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $lateNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($lateNumber),
            'proofs_generated_at' => Carbon::now()->subMinute(),
        ]);

        $small = $this->smallSuffix();
        $large = $this->largeSuffix();

        // Fake rsync: a new derivative set appears on disk *during* the run,
        // but the reported manifest only contains the file rsync enumerated.
        $script = <<<SH
#!/bin/bash
src="\${@: -2:1}"
printf '' > "\$src/{$lateNumber}{$small}.jpg"
printf '' > "\$src/{$lateNumber}{$large}.jpg"
printf '>f+++++++++ {$complete->proof_number}{$small}.jpg\n'
printf '>f+++++++++ {$complete->proof_number}{$large}.jpg\n'
exit 0
SH;

        $this->bindFakeRsync($script);

        $result = $this->class->proofUploads();

        $this->assertNotNull($complete->fresh()->proofs_uploaded_at);
        $this->assertNull($late->fresh()->proofs_uploaded_at, 'a file rsync never reported must not be stamped');
        $this->assertArrayNotHasKey($lateNumber, $result);
    }

    public function test_file_disappearing_before_rsync_sees_it_is_not_stamped(): void
    {
        $proofNumber = 'SHOW1_00032';
        $photo = $this->seedPhotoWithProofs($proofNumber);

        $small = $this->smallSuffix();
        $large = $this->largeSuffix();

        // One variant vanishes before rsync's own enumeration, so its stdout
        // never mentions it; the pair is incomplete and must not be stamped.
        $script = <<<SH
#!/bin/bash
src="\${@: -2:1}"
rm -f "\$src/{$proofNumber}{$small}.jpg"
printf '>f+++++++++ {$proofNumber}{$large}.jpg\n'
exit 0
SH;

        $this->bindFakeRsync($script);

        $result = $this->class->proofUploads();

        $this->assertNull($photo->fresh()->proofs_uploaded_at);
        $this->assertArrayHasKey($proofNumber, $result);
        $this->assertCount(1, $result[$proofNumber], 'only the rsync-reported variant belongs to the manifest');
        $this->assertStringContainsString($large, $result[$proofNumber][0]);
    }

    public function test_successful_retransfer_of_changed_derivative_advances_upload_timestamp(): void
    {
        $proofNumber = 'SHOW1_00033';
        $photo = $this->seedPhotoWithProofs($proofNumber);

        $this->class->proofUploads();
        $firstStamp = $photo->fresh()->proofs_uploaded_at;
        $this->assertNotNull($firstStamp);

        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        try {
            $smallPath = 'proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg';
            Storage::disk('fullsize')->put($smallPath, 'changed bytes '.str_repeat('x', 32));
            touch(Storage::disk('fullsize')->path($smallPath), time() + 60);

            $this->class->proofUploads();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue(
            $photo->fresh()->proofs_uploaded_at->greaterThan($firstStamp),
            'a real retransfer must advance the last-uploaded timestamp'
        );
        $this->assertSame(
            Storage::disk('fullsize')->get($smallPath),
            Storage::disk('remote_proofs')->get('SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg')
        );
    }

    public function test_permission_only_change_does_not_advance_upload_timestamp(): void
    {
        $proofNumber = 'SHOW1_00034';
        $photo = $this->seedPhotoWithProofs($proofNumber);

        $this->class->proofUploads();
        $firstStamp = $photo->fresh()->proofs_uploaded_at;
        $this->assertNotNull($firstStamp);

        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        try {
            $smallAbsolute = Storage::disk('fullsize')->path('proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg');
            chmod($smallAbsolute, 0600);

            $this->class->proofUploads();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            $firstStamp->timestamp,
            $photo->fresh()->proofs_uploaded_at->timestamp,
            'an attribute-only rsync change is not a content upload'
        );
    }

    public function test_missing_remote_target_fails_real_class_upload_before_storage_or_rsync(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00040');

        // A bogus disk driver proves the configuration check runs before any
        // Storage access; the mock proves rsync is never started.
        config([
            'proofgen.sftp.path' => '',
            'filesystems.disks.remote_proofs' => ['driver' => 'definitely-not-a-real-driver'],
        ]);
        Storage::forgetDisk('remote_proofs');

        $runner = Mockery::mock(RsyncRunner::class);
        $runner->shouldNotReceive('run');
        $this->app->instance(RsyncRunner::class, $runner);

        try {
            $this->class->proofUploads();
            $this->fail('Expected UploadConfigurationException when the proofs destination is unset.');
        } catch (UploadConfigurationException $e) {
            $this->assertStringContainsString('proofgen.sftp.path', $e->getMessage());
            $this->assertStringContainsString('class SHOW1_101', $e->getMessage());
        }

        $this->assertNull($photo->fresh()->proofs_uploaded_at);
    }

    public function test_missing_remote_target_fails_real_show_upload_before_storage(): void
    {
        $photo = $this->seedPhotoWithProofs('SHOW1_00041');

        config([
            'proofgen.sftp.path' => '',
            'filesystems.disks.remote_proofs' => ['driver' => 'definitely-not-a-real-driver'],
        ]);
        Storage::forgetDisk('remote_proofs');

        try {
            $this->show->proofUploads();
            $this->fail('Expected UploadConfigurationException when the proofs destination is unset.');
        } catch (UploadConfigurationException $e) {
            $this->assertStringContainsString('proofgen.sftp.path', $e->getMessage());
            $this->assertStringContainsString('show SHOW1', $e->getMessage());
        }

        $this->assertNull($photo->fresh()->proofs_uploaded_at);
    }

    public function test_missing_optional_highres_target_still_allows_pending_checks(): void
    {
        $photo = $this->seedPhotoWithHighresImage('SHOW1_00042');

        config([
            'proofgen.sftp.highres_images_path' => '',
            'filesystems.disks.remote_highres_images' => ['driver' => 'definitely-not-a-real-driver'],
        ]);
        Storage::forgetDisk('remote_highres_images');

        $this->assertSame([], $this->class->pendingHighresImageUploads());
        $this->assertSame([], $this->show->pendingHighresImageUploads());

        $summary = $this->show->checkAllUploads();
        $this->assertSame([], $summary['highres_images_pending_upload']);
        $this->assertNull($photo->fresh()->highres_image_uploaded_at);

        try {
            $this->class->highresImageUploads();
            $this->fail('Expected UploadConfigurationException for a real highres upload without a destination.');
        } catch (UploadConfigurationException $e) {
            $this->assertStringContainsString('proofgen.sftp.highres_images_path', $e->getMessage());
        }
    }

    public function test_missing_upload_target_stops_chain_before_metadata_push(): void
    {
        $proofNumber = 'SHOW1_00043';
        $this->seedPhotoWithAllDerivatives($proofNumber);

        $profile = StorageProfile::findOrFail(StorageProfile::LEGACY_LOCAL_ID);
        $this->show->storage_profile_id = $profile->id;
        $this->show->save();

        config([
            'proofgen.sftp.path' => '',
            'proofgen.ferraraphoto.api_token' => 'unit-test-token',
        ]);

        $api = Mockery::mock(FerraraphotoApiClient::class);
        $api->shouldNotReceive('upsertPhotos');
        $this->app->instance(FerraraphotoApiClient::class, $api);

        try {
            Bus::chain([
                new UploadDerivedFiles('SHOW1_101'),
                new PushPhotoMetadata('SHOW1_101'),
            ])->dispatch();
            $this->fail('Expected the missing destination to abort the chain.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(UploadConfigurationException::class, $e);
        }

        // Had PushPhotoMetadata run, it would have resolved the mocked client
        // and called upsertPhotos; Mockery verifies the never() at teardown.
    }

    private function seedPhotoWithAllDerivatives(string $proofNumber): Photo
    {
        $photo = Photo::create([
            'id' => 'SHOW1_101_'.$proofNumber,
            'show_class_id' => 'SHOW1_101',
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
            'sha1' => sha1($proofNumber.'_all'),
            'proofs_generated_at' => Carbon::now()->subMinute(),
            'web_image_generated_at' => Carbon::now()->subMinute(),
            'highres_image_generated_at' => Carbon::now()->subMinute(),
        ]);

        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg', 'thm '.$proofNumber);
        Storage::disk('fullsize')->put('proofs/SHOW1/101/'.$proofNumber.$this->largeSuffix().'.jpg', 'std '.$proofNumber);
        Storage::disk('fullsize')->put('web_images/SHOW1/101/'.$proofNumber.$this->webSuffix().'.jpg', 'web '.$proofNumber);
        Storage::disk('fullsize')->put('highres_images/SHOW1/101/'.$proofNumber.$this->highresSuffix().'.jpg', 'highres '.$proofNumber);

        return $photo->fresh();
    }

    private function seamMethod(string $type, bool $dryRun): string
    {
        return match ($type) {
            'proofs' => $dryRun ? 'pendingProofUploads' : 'proofUploads',
            'web_images' => $dryRun ? 'pendingWebImageUploads' : 'webImageUploads',
            'highres_images' => $dryRun ? 'pendingHighresImageUploads' : 'highresImageUploads',
            default => throw new \InvalidArgumentException("Unknown sync type: {$type}"),
        };
    }

    private function uploadedColumn(string $type): string
    {
        return match ($type) {
            'proofs' => 'proofs_uploaded_at',
            'web_images' => 'web_image_uploaded_at',
            'highres_images' => 'highres_image_uploaded_at',
            default => throw new \InvalidArgumentException("Unknown sync type: {$type}"),
        };
    }

    private function remoteDisk(string $type): string
    {
        return match ($type) {
            'proofs' => 'remote_proofs',
            'web_images' => 'remote_web_images',
            'highres_images' => 'remote_highres_images',
            default => throw new \InvalidArgumentException("Unknown sync type: {$type}"),
        };
    }

    private function localRelativePathFor(string $type, string $proofNumber): string
    {
        return match ($type) {
            'proofs' => 'proofs/SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg',
            'web_images' => 'web_images/SHOW1/101/'.$proofNumber.$this->webSuffix().'.jpg',
            'highres_images' => 'highres_images/SHOW1/101/'.$proofNumber.$this->highresSuffix().'.jpg',
            default => throw new \InvalidArgumentException("Unknown sync type: {$type}"),
        };
    }

    /**
     * @return array<int, string>
     */
    private function remotePathsFor(string $type, string $proofNumber): array
    {
        return match ($type) {
            'proofs' => [
                'SHOW1/101/'.$proofNumber.$this->smallSuffix().'.jpg',
                'SHOW1/101/'.$proofNumber.$this->largeSuffix().'.jpg',
            ],
            'web_images' => ['SHOW1/101/'.$proofNumber.$this->webSuffix().'.jpg'],
            'highres_images' => ['SHOW1/101/'.$proofNumber.$this->highresSuffix().'.jpg'],
            default => throw new \InvalidArgumentException("Unknown sync type: {$type}"),
        };
    }

    private function smallSuffix(): string
    {
        return (string) config('proofgen.thumbnails.small.suffix');
    }

    private function largeSuffix(): string
    {
        return (string) config('proofgen.thumbnails.large.suffix');
    }

    private function webSuffix(): string
    {
        return (string) config('proofgen.web_images.suffix');
    }

    private function highresSuffix(): string
    {
        return (string) config('proofgen.highres_images.suffix');
    }

    /**
     * Fake rsync: prints the supplied itemized lines (partial stdout), writes a
     * diagnostic to stderr, then exits non-zero.
     *
     * @param  array<int, string>  $itemizedPaths
     */
    private function failingRsyncScript(array $itemizedPaths, int $exitCode = 23): string
    {
        $script = "#!/bin/bash\n";

        foreach ($itemizedPaths as $path) {
            $script .= 'printf '.escapeshellarg('>f+++++++++ '.$path."\n")."\n";
        }

        $script .= "echo 'simulated connection drop after partial transfer' >&2\n";
        $script .= "exit {$exitCode}\n";

        return $script;
    }

    /**
     * Fake rsync that fails the proofs tree but otherwise mimics a real local
     * copy, so a chain that wrongly continued would leave web/highres files.
     */
    private function failingProofsOnlyRsyncScript(string $proofNumber): string
    {
        $itemized = escapeshellarg('>f+++++++++ 101/'.$proofNumber.$this->smallSuffix().'.jpg')

            ."\n".escapeshellarg('>f+++++++++ 101/'.$proofNumber.$this->largeSuffix().'.jpg');

        return <<<SH
#!/bin/bash
src="\${@: -2:1}"
dest="\${@: -1:1}"

if [[ "\$src" == *proofs* ]]; then
  printf '%s\n' {$itemized}
  echo 'simulated proofs failure' >&2
  exit 23
fi

mkdir -p "\$dest"
find "\$src" -type f | while read -r f; do
  rel="\${f#\$src}"
  mkdir -p "\$dest/\$(dirname "\$rel")"
  cp "\$f" "\$dest/\$rel"
done
printf '%s\n' "\$src -> \$dest (fake copy)"
exit 0
SH;
    }
}
