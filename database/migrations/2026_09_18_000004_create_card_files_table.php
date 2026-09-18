<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Card Reader put each file's archive copy, by content hash.
 *
 * The Card Reader writes the archive copy when a card is dumped. When that
 * photo is imported, the import looks its hash up here and RENAMES the file on
 * the archive drive to its final proof-number name instead of writing the same
 * bytes a second time. `claimed_at` marks copies that have moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_files', function (Blueprint $table) {
            $table->id();
            $table->string('sha1', 40)->index();
            $table->unsignedBigInteger('size');
            $table->string('show_id');
            $table->string('class_folder');
            $table->string('archive_path');
            $table->timestamp('dumped_at');
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_files');
    }
};
