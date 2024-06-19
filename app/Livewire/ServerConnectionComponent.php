<?php

namespace App\Livewire;

use App\Proofgen\Utility;
use Hamcrest\Util;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV2\SftpAdapter;
use League\Flysystem\PhpseclibV2\SftpConnectionProvider;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use Livewire\Component;

class ServerConnectionComponent extends Component
{
    public string $host = '';
    public int $port = 22;
    public string $username = '';
    public string $key_path = '';
    public string $proofs_path = '';
    public string $debug_output = '';

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
        $listing = Storage::disk('remote_proofs')->directories();

        $paths = [];
        // Get and store the paths of only the directories
        foreach ($listing as $item) {
            $paths[] = $item;
        }
        if(count($paths)) {
            $this->debug_output = 'Connection successful, found '.count($paths).' items in the remote directory.';
        } else {
            $this->debug_output = 'Connection successful, but no items found in the remote directory.';
        }

    }

    public function render()
    {
        return view('livewire.server-connection-component');
    }
}
