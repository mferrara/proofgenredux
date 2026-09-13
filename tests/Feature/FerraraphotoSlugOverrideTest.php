<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\ShowClass;
use App\Services\FerraraphotoTargetVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies that the proofgen Show.ferraraphoto_show_slug column decouples
 * the in-app show id from the directory-name slug used on the ferraraphoto
 * host. With null override, behavior matches pre-existing behavior (show id
 * is the slug). With an override, all remote-path operations honor it.
 */
class FerraraphotoSlugOverrideTest extends TestCase
{
    use RefreshDatabase;

    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = storage_path('app/ferraraphoto_slug_test_'.uniqid());
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
            Show::create(['id' => '22Buck', 'name' => '22Buck']);
        });
        ShowClass::withoutEvents(function () {
            ShowClass::create(['id' => '22Buck_007', 'show_id' => '22Buck', 'name' => '007']);
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && File::exists($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }
        parent::tearDown();
    }

    public function test_ferraraphoto_slug_defaults_to_show_id_when_override_null(): void
    {
        $show = Show::find('22Buck');

        $this->assertSame('22Buck', $show->ferraraphoto_slug);
        $this->assertNull($show->ferraraphoto_show_slug);
    }

    public function test_ferraraphoto_slug_uses_override_when_set(): void
    {
        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = 'buck-show-2024';
        $show->save();

        $this->assertSame('buck-show-2024', $show->fresh()->ferraraphoto_slug);
    }

    public function test_ferraraphoto_slug_treats_blank_override_as_no_override(): void
    {
        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = '   ';
        $show->save();

        $this->assertSame('22Buck', $show->fresh()->ferraraphoto_slug);
    }

    public function test_verifier_uses_override_slug_for_show_check(): void
    {
        // Remote dirs exist under the OVERRIDE slug, not the proofgen show id.
        File::makeDirectory($this->tempPath.'/remote-proofs/buck-show-2024', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-web/buck-show-2024', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-highres/buck-show-2024', 0755, true);

        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = 'buck-show-2024';
        $show->save();

        $result = app(FerraraphotoTargetVerifier::class)->verifyShow($show->fresh());

        $this->assertTrue($result['proofs']['exists']);
        $this->assertTrue($result['web_images']['exists']);
        $this->assertTrue($result['highres_images']['exists']);
        $this->assertSame('buck-show-2024', $result['proofs']['path']);
    }

    public function test_verifier_uses_override_slug_for_class_check(): void
    {
        File::makeDirectory($this->tempPath.'/remote-proofs/buck-show-2024/007', 0755, true);

        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = 'buck-show-2024';
        $show->save();

        // Refresh the relation so the verifier sees the override.
        $class = ShowClass::with('show')->find('22Buck_007');

        $result = app(FerraraphotoTargetVerifier::class)->verifyClass($class);

        $this->assertTrue($result['proofs']['exists']);
        $this->assertSame('buck-show-2024/007', $result['proofs']['path']);
    }

    public function test_show_class_remote_path_attributes_use_override_slug(): void
    {
        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = 'buck-show-2024';
        $show->save();

        $class = ShowClass::with('show')->find('22Buck_007');

        $this->assertSame('buck-show-2024/007', $class->remote_proofs_path);
        $this->assertSame('buck-show-2024/007', $class->remote_web_images_path);
        $this->assertSame('buck-show-2024/007', $class->remote_highres_images_path);
    }

    public function test_show_class_local_path_attributes_still_use_show_id(): void
    {
        // Local paths should NEVER use the override — those are on-disk directories
        // matching the proofgen show id, which the operator never changes.
        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = 'buck-show-2024';
        $show->save();

        $class = ShowClass::with('show')->find('22Buck_007');

        $this->assertSame('proofs/22Buck/007', $class->proofs_path);
        $this->assertSame('web_images/22Buck/007', $class->web_images_path);
        $this->assertSame('highres_images/22Buck/007', $class->highres_images_path);
    }

    public function test_verifier_class_string_and_model_forms_honor_override_consistently(): void
    {
        File::makeDirectory($this->tempPath.'/remote-proofs/buck-show-2024/007', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-web/buck-show-2024/007', 0755, true);
        File::makeDirectory($this->tempPath.'/remote-highres/buck-show-2024/007', 0755, true);

        $show = Show::find('22Buck');
        $show->ferraraphoto_show_slug = 'buck-show-2024';
        $show->save();

        $verifier = app(FerraraphotoTargetVerifier::class);
        $class = ShowClass::with('show')->find('22Buck_007');

        $modelResult = $verifier->verifyClass($class);
        $stringResult = $verifier->verifyClass('22Buck_007');

        $this->assertTrue($modelResult['all_exist']);
        $this->assertTrue($stringResult['all_exist']);
        $this->assertSame('buck-show-2024/007', $modelResult['proofs']['path']);
        $this->assertSame($modelResult['proofs']['path'], $stringResult['proofs']['path']);
        $this->assertSame($modelResult['web_images']['path'], $stringResult['web_images']['path']);
        $this->assertSame($modelResult['highres_images']['path'], $stringResult['highres_images']['path']);
    }
}
