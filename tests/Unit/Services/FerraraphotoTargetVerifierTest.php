<?php

namespace Tests\Unit\Services;

use App\Models\Show;
use App\Models\ShowClass;
use App\Services\FerraraphotoTargetVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FerraraphotoTargetVerifierTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/ferraraphoto_verifier_test_'.uniqid());
        File::makeDirectory($this->tempPath.'/remote-proofs', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-web', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-highres', 0755, true);

        config(['filesystems.disks.remote_proofs' => [
            'driver' => 'local', 'root' => $this->tempPath.'/remote-proofs', 'throw' => false,
        ]]);
        config(['filesystems.disks.remote_web_images' => [
            'driver' => 'local', 'root' => $this->tempPath.'/remote-web', 'throw' => false,
        ]]);
        config(['filesystems.disks.remote_highres_images' => [
            'driver' => 'local', 'root' => $this->tempPath.'/remote-highres', 'throw' => false,
        ]]);
        Storage::forgetDisk('remote_proofs');
        Storage::forgetDisk('remote_web_images');
        Storage::forgetDisk('remote_highres_images');

        Show::withoutEvents(function () {
            Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => 'SHOW1_101', 'show_id' => 'SHOW1', 'name' => '101']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
        parent::tearDown();
    }

    public function test_verify_show_reports_all_three_disks_missing_when_show_dir_absent(): void
    {
        $result = app(FerraraphotoTargetVerifier::class)->verifyShow(Show::find('SHOW1'));

        $this->assertFalse($result['proofs']['exists']);
        $this->assertFalse($result['web_images']['exists']);
        $this->assertFalse($result['highres_images']['exists']);
        $this->assertFalse($result['all_exist']);
        $this->assertFalse($result['any_exist']);
        $this->assertFalse($result['any_errored']);
        $this->assertSame('SHOW1', $result['proofs']['path']);
    }

    public function test_verify_show_reports_partial_when_some_disks_have_dir(): void
    {
        // Create only the proofs dir for the show, not web or highres
        File::makeDirectory($this->tempPath.'/remote-proofs/SHOW1', 0755, true);

        $result = app(FerraraphotoTargetVerifier::class)->verifyShow('SHOW1');

        $this->assertTrue($result['proofs']['exists']);
        $this->assertFalse($result['web_images']['exists']);
        $this->assertFalse($result['highres_images']['exists']);
        $this->assertFalse($result['all_exist']);
        $this->assertTrue($result['any_exist']);
    }

    public function test_verify_show_reports_all_exist_when_all_three_dirs_present(): void
    {
        File::makeDirectory($this->tempPath.'/remote-proofs/SHOW1', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-web/SHOW1', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-highres/SHOW1', 0755, true);

        $result = app(FerraraphotoTargetVerifier::class)->verifyShow('SHOW1');

        $this->assertTrue($result['all_exist']);
        $this->assertTrue($result['any_exist']);
    }

    public function test_verify_class_checks_class_subdirectories(): void
    {
        // Create show dir but NOT class dir
        File::makeDirectory($this->tempPath.'/remote-proofs/SHOW1', 0755, true);

        $result = app(FerraraphotoTargetVerifier::class)->verifyClass(ShowClass::find('SHOW1_101'));

        $this->assertFalse($result['proofs']['exists'], 'class subdir should not exist yet');
        $this->assertSame('SHOW1/101', $result['proofs']['path']);
    }

    public function test_verify_class_reports_all_exist_when_class_dirs_present_on_each_disk(): void
    {
        File::makeDirectory($this->tempPath.'/remote-proofs/SHOW1/101', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-web/SHOW1/101', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-highres/SHOW1/101', 0755, true);

        $result = app(FerraraphotoTargetVerifier::class)->verifyClass('SHOW1_101');

        $this->assertTrue($result['all_exist']);
    }

    public function test_verify_class_handles_invalid_class_id_string(): void
    {
        $result = app(FerraraphotoTargetVerifier::class)->verifyClass('no-underscore-here');

        $this->assertFalse($result['all_exist']);
        $this->assertNotNull($result['proofs']['error']);
    }
}
