<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add the transport driver configuration. 'sftp' (default) keeps the
     * existing rsync-over-SSH behavior; 'local' switches to a plain local
     * rsync against the configured paths — useful for local development
     * against a sibling install on the same machine and for any future
     * deployment where proofgen runs on the same host as the website.
     */
    public function up(): void
    {
        Configuration::setConfig(
            'sftp.driver',
            'sftp',
            'string',
            'sftp',
            'Connection mode',
            "How proofs / web / highres images are pushed to the website. 'sftp' uses rsync-over-SSH (production). 'local' skips SSH and writes directly to the configured paths on this machine."
        );

        // Also surface the SFTP username so it's no longer hardcoded as 'forge'
        // in the rsync commands. Defaults to 'forge' for back-compat.
        if (! Configuration::where('key', 'sftp.username')->exists()) {
            Configuration::setConfig(
                'sftp.username',
                getenv('SFTP_USERNAME') ?: 'forge',
                'string',
                'sftp',
                'SFTP Username',
                'SSH user to connect as when transport driver is sftp.'
            );
        }
    }

    public function down(): void
    {
        Configuration::where('key', 'sftp.driver')->delete();
        Configuration::where('key', 'sftp.username')->delete();
    }
};
