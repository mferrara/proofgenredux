<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class AppStatusBar extends Component
{
    // Reload when the configuration changes
    protected $listeners = [
        'config-updated' => 'reload',
    ];

    #[On('config-updated')]
    public function reload()
    {
        \Log::debug('AppStatusBar reload called');
    }

    public function render()
    {
        \Log::debug('AppStatusBar render called');
        return view('livewire.app-status-bar');
    }
}
