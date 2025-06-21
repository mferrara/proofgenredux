<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Services\ImageEnhancementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageEnhancementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable enhancement for testing
        Configuration::setConfig('image_enhancement_enabled', 'true', 'boolean');
        Configuration::setConfig('image_enhancement_method', 'basic_auto_levels', 'string');
        Configuration::setConfig('enhancement_apply_to_proofs', 'true', 'boolean');
        Configuration::setConfig('enhancement_apply_to_web', 'true', 'boolean');
        Configuration::setConfig('enhancement_apply_to_highres', 'true', 'boolean');
    }

    protected function tearDown(): void
    {
        // Reset enhancement settings
        Configuration::where('key', 'like', '%enhancement%')->delete();

        parent::tearDown();
    }

    /** @test */
    public function it_can_check_enhancement_is_configured()
    {
        // Force configuration override
        Configuration::overrideApplicationConfig();

        $this->assertTrue(config('proofgen.image_enhancement_enabled'));
        $this->assertEquals('basic_auto_levels', config('proofgen.image_enhancement_method'));
        $this->assertTrue(config('proofgen.enhancement_apply_to_proofs'));
    }

    /** @test */
    public function it_can_get_enhancement_service()
    {
        $service = app(ImageEnhancementService::class);

        $this->assertInstanceOf(ImageEnhancementService::class, $service);
    }

    /** @test */
    public function it_respects_disabled_enhancement_setting()
    {
        Configuration::setConfig('image_enhancement_enabled', 'false', 'boolean');
        Configuration::overrideApplicationConfig();

        $this->assertFalse(config('proofgen.image_enhancement_enabled'));
    }

    /** @test */
    public function it_can_change_enhancement_method()
    {
        Configuration::setConfig('image_enhancement_method', 'smart_indoor', 'string');
        Configuration::overrideApplicationConfig();

        $this->assertEquals('smart_indoor', config('proofgen.image_enhancement_method'));
    }

    /** @test */
    public function it_can_toggle_enhancement_for_different_image_types()
    {
        Configuration::setConfig('enhancement_apply_to_proofs', 'false', 'boolean');
        Configuration::setConfig('enhancement_apply_to_web', 'true', 'boolean');
        Configuration::setConfig('enhancement_apply_to_highres', 'false', 'boolean');
        Configuration::overrideApplicationConfig();

        $this->assertFalse(config('proofgen.enhancement_apply_to_proofs'));
        $this->assertTrue(config('proofgen.enhancement_apply_to_web'));
        $this->assertFalse(config('proofgen.enhancement_apply_to_highres'));
    }
}
