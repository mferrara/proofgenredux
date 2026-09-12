<?php

it('shows an enhancement failure without claiming the preview was enhanced', function () {
    $this->blade('@include("livewire.partials.preview-info-label", ["enhancementInfo" => $info])', [
        'info' => ['enabled' => false, 'error' => 'Advanced tone mapping requires Core Image.'],
    ])
        ->assertSee('Enhancement not applied:')
        ->assertSee('Advanced tone mapping requires Core Image.')
        ->assertDontSee('Enhanced:');
});

it('labels successfully applied preview adjustments', function () {
    $this->blade('@include("livewire.partials.preview-info-label", ["enhancementInfo" => $info])', [
        'info' => ['enabled' => true, 'method_label' => 'Auto-Levels', 'parameters' => 'Target: 140'],
    ])
        ->assertSee('Enhanced:')
        ->assertSee('Auto-Levels Target: 140')
        ->assertDontSee('Enhancement not applied:');
});
