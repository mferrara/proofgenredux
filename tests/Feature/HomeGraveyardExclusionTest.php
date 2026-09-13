<?php

namespace Tests\Feature;

use App\Livewire\HomeComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders Home against a synthetic fullsize disk and confirms the internal
 * _graveyard tree is hidden while ordinary show directories are preserved.
 */
class HomeGraveyardExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_hides_graveyard_and_keeps_show_directories(): void
    {
        config(['testing.skip_file_operations' => true]);

        Storage::fake('fullsize');

        Storage::disk('fullsize')->put('_graveyard/2026-01-01/LEGIT_SHOW/101/IMG_0001_20260101-000000_abcdef12.jpg', 'buried');
        Storage::disk('fullsize')->put('LEGIT_SHOW/101/IMG_0001.jpg', 'pending');
        Storage::disk('fullsize')->put('2023_R41/IMG_0001.jpg', 'pending');

        $component = Livewire::test(HomeComponent::class)->assertSuccessful();

        $directories = $component->viewData('top_level_directories');

        $this->assertContains('LEGIT_SHOW', $directories);
        $this->assertContains('2023_R41', $directories);
        $this->assertNotContains('_graveyard', $directories);

        $component->assertDontSee('_graveyard')
            ->assertSee('LEGIT_SHOW')
            ->assertSee('2023_R41');
    }
}
