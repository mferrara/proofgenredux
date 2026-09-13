<?php

namespace Tests\Feature;

use App\Livewire\ShowViewComponent;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders the show page against a synthetic filesystem: a class folder with
 * pending ingest files but zero imported photos must not claim "All done".
 */
class ShowClassPendingStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_class_with_pending_imports_is_not_all_done(): void
    {
        config(['testing.skip_file_operations' => true]);

        Storage::fake('fullsize');
        Storage::fake('archive');

        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW1', 'name' => 'SHOW1']));

        for ($i = 1; $i <= 8; $i++) {
            Storage::disk('fullsize')->put('SHOW1/101/IMG_000'.$i.'.jpg', 'pending '.$i);
        }

        $component = Livewire::test(ShowViewComponent::class, ['show_id' => 'SHOW1'])
            ->assertSuccessful();

        $component->assertSee('Pending import: 8')
            ->assertDontSee('All done');

        $this->assertSame(0, ShowClass::find('SHOW1_101')->photos()->count());
    }
}
