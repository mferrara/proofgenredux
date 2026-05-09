<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_issues', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->default('open');
            $table->string('issue_type', 64);
            $table->string('show_id')->nullable();
            $table->string('show_class_id')->nullable();
            $table->string('source_path')->nullable();
            $table->string('quarantine_path')->nullable();
            $table->string('intended_proof_number', 64)->nullable();
            $table->string('incoming_sha1', 40)->nullable();
            $table->unsignedBigInteger('incoming_size')->nullable();
            $table->timestamp('incoming_mtime')->nullable();
            $table->string('existing_photo_id')->nullable();
            $table->string('existing_proof_number', 64)->nullable();
            $table->string('existing_sha1', 40)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by_user')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'issue_type'], 'idx_photo_issues_status_type');
            $table->index(['show_class_id', 'status'], 'idx_photo_issues_class_status');
            $table->index('incoming_sha1', 'idx_photo_issues_incoming_sha1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_issues');
    }
};
