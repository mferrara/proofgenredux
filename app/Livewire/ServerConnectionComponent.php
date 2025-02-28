<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class ServerConnectionComponent extends Component
{
    public string $host = '';
    public int $port = 22;
    public string $username = '';
    public string $key_path = '';
    public string $proofs_path = '';
    public string $debug_output = '';
    public array $paths_found = [];
    public bool $server_connection_test_result = false;
    public bool $horizon_is_running = false;

    public function mount(): void
    {
        $this->host = config('proofgen.sftp.host');
        $this->port = config('proofgen.sftp.port', 22);
        $this->username = config('proofgen.sftp.username');
        $this->key_path = config('proofgen.sftp.private_key');
        $this->proofs_path = config('proofgen.sftp.path');
    }

    public function testConnection(): void
    {
        $this->debug_output = '';
        // Try to get a directory listing from the base path
        try{
            $listing = Storage::disk('remote_proofs')->directories();
        } catch(\Throwable $e) {
            $this->debug_output = 'Connection failed: '.$e->getMessage();
            $this->server_connection_test_result = false;
            return;
        }
        $this->server_connection_test_result = true;

        $paths_found = [];
        // Get and store the paths of only the directories
        foreach ($listing as $item) {
            $paths_found[] = $item;
        }
        if(count($paths_found)) {
            $this->debug_output = 'Connection successful';
        } else {
            $this->debug_output = 'Connection successful, but no items found in the remote directory.';
        }
        $this->paths_found = $paths_found;
    }

    public function render()
    {
        // Determine if Horizon is running and set flag
        $this->horizon_is_running = app()->bound('horizon') && app('horizon')->running();

        return view('livewire.server-connection-component');
    }
}
