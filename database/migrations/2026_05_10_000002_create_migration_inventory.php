<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_inventory', function (Blueprint $table) {
            $table->id();
            $table->string('show_slug', 64);
            $table->string('class_number', 32);
            $table->string('proof_number', 64);
            $table->string('content_type', 16);
            $table->string('source_disk', 32);
            $table->string('source_path');
            $table->unsignedBigInteger('source_size_bytes')->nullable();
            $table->timestamp('source_mtime')->nullable();
            $table->string('target_disk', 64)->nullable();
            $table->string('target_object_key')->nullable();
            $table->string('source_sha1', 40)->nullable();
            $table->string('target_sha1', 40)->nullable();
            $table->string('status', 16)->default('discovered');
            $table->text('error_message')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['show_slug', 'class_number', 'proof_number', 'content_type'], 'idx_migration_inventory_unique_file');
            $table->index('status');
            $table->index(['show_slug', 'status'], 'idx_migration_inventory_show_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_inventory');
    }
};
