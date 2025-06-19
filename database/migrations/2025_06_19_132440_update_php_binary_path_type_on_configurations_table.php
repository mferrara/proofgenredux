<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get the php_binary_path column from the configurations table and update it's type to 'path'
        $php_binary_path = \App\Models\Configuration::where('key', 'php_binary_path')->first();
        if ($php_binary_path) {
            $php_binary_path->type = 'path';
            $php_binary_path->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
