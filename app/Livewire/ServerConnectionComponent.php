<?php

namespace App\Livewire;

use App\Models\Show;
use App\Models\StorageProfile;
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

    public int $legacyLocalShowCount = 0;

    public function mount(): void
    {
        $this->host = config('proofgen.sftp.host');
        $this->port = config('proofgen.sftp.port', 22);
        $this->username = config('proofgen.sftp.username');
        $this->key_path = config('proofgen.sftp.private_key');
        $this->proofs_path = config('proofgen.sftp.path');
        $this->legacyLocalShowCount = Show::query()
            ->where('storage_profile_id', StorageProfile::LEGACY_LOCAL_ID)
            ->count();
    }

    public function testConnection(): void
    {
        if ($this->legacyLocalShowCount === 0) {
            $this->debug_output = 'Legacy SFTP is not currently used by any show.';
            $this->paths_found = [];
            $this->server_connection_test_result = false;

            return;
        }

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
            ->title('Legacy SFTP - Proofgen');
    }
}
