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
        $this->paths_found = [];

        try {
            $listing = Storage::disk('remote_proofs')->directories();
        } catch (\Throwable $e) {
            $this->debug_output = 'Connection failed: '.$e->getMessage();
            $this->server_connection_test_result = false;

            return;
        }

        $this->server_connection_test_result = true;
        $this->paths_found = $listing;

        $this->debug_output = count($listing) > 0
            ? 'Connection successful'
            : 'Connection successful, but no items found in the remote directory.';
    }

    public function render()
    {
        return view('livewire.server-connection-component')
            ->title('Server Connection - Proofgen');
    }
}
