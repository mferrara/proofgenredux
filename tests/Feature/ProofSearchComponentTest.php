<?php

namespace Tests\Feature;

use App\Livewire\ProofSearchComponent;
use App\Models\Photo;
use App\Models\Show;
use App\Models\ShowClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProofSearchComponentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['testing.skip_file_operations' => true]);
    }

    public function test_results_use_relation_for_underscored_show_and_class_ids(): void
    {
        $photo = $this->makePhoto('2023_R41', 'opening_ceremony', '00042');

        Livewire::test(ProofSearchComponent::class)
            ->set('query', '00042')
            ->assertSet('showDropdown', true)
            ->assertSet('results', [[
                'id' => $photo->id,
                'proof_number' => '00042',
                'show_class_id' => '2023_R41_opening_ceremony',
                'show_name' => '2023_R41',
                'class_name' => 'opening_ceremony',
            ]]);
    }

    public function test_select_redirects_using_relation_when_display_name_differs_from_class_id(): void
    {
        Show::withoutEvents(fn () => Show::create(['id' => 'SHOW_A', 'name' => 'Display Name A']));
        ShowClass::withoutEvents(fn () => ShowClass::create([
            'id' => 'SHOW_A_B_C',
            'show_id' => 'SHOW_A',
            'name' => 'B_C',
        ]));

        $photo = Photo::create([
            'show_class_id' => 'SHOW_A_B_C',
            'proof_number' => '12345',
            'file_type' => 'jpg',
        ]);

        Livewire::test(ProofSearchComponent::class)
            ->set('query', '12345')
            ->assertSet('results', [[
                'id' => $photo->id,
                'proof_number' => '12345',
                'show_class_id' => 'SHOW_A_B_C',
                'show_name' => 'SHOW_A',
                'class_name' => 'B_C',
            ]])
            ->call('selectProof', $photo->id)
            ->assertRedirect('/show/SHOW_A/class/B_C');
    }

    public function test_select_proof_encodes_route_segments(): void
    {
        $photo = $this->makePhoto('SHOW A', 'Class A', '99999');

        Livewire::test(ProofSearchComponent::class)
            ->call('selectProof', $photo->id)
            ->assertRedirect('/show/SHOW%20A/class/Class%20A');
    }

    public function test_missing_show_class_relation_does_not_crash(): void
    {
        $photo = Photo::create([
            'show_class_id' => 'ORPHAN_99',
            'proof_number' => '55555',
            'file_type' => 'jpg',
        ]);

        $component = Livewire::test(ProofSearchComponent::class)
            ->set('query', '55555')
            ->assertSet('showDropdown', true)
            ->assertSet('results', [[
                'id' => $photo->id,
                'proof_number' => '55555',
                'show_class_id' => 'ORPHAN_99',
                'show_name' => null,
                'class_name' => null,
            ]]);

        $component->call('selectProof', $photo->id)
            ->assertSet('showDropdown', false)
            ->assertSet('query', '55555');
    }

    public function test_clear_search_resets_state(): void
    {
        $this->makePhoto('2023_R41', 'opening_ceremony', '00042');

        Livewire::test(ProofSearchComponent::class)
            ->set('query', '00042')
            ->assertSet('showDropdown', true)
            ->call('clearSearch')
            ->assertSet('query', '')
            ->assertSet('selectedProofNumber', null)
            ->assertSet('results', [])
            ->assertSet('showDropdown', false);
    }

    private function makePhoto(string $showId, string $className, string $proofNumber): Photo
    {
        Show::withoutEvents(fn () => Show::firstOrCreate(['id' => $showId], ['name' => $showId]));
        ShowClass::withoutEvents(fn () => ShowClass::firstOrCreate(
            ['id' => $showId.'_'.$className],
            ['show_id' => $showId, 'name' => $className],
        ));

        return Photo::create([
            'show_class_id' => $showId.'_'.$className,
            'proof_number' => $proofNumber,
            'file_type' => 'jpg',
        ]);
    }
}
