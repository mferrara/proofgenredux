<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('proof_number')->index();
            $table->string('show_class_id')->index();
            $table->string('sha1', 40)->index()->nullable();
            $table->string('file_type');
            $table->timestamp('proofs_generated_at')->nullable();
            $table->timestamp('proofs_uploaded_at')->nullable();
            $table->timestamp('web_image_generated_at')->nullable();
            $table->timestamp('web_image_uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['show_class_id', 'proofs_generated_at'], 'idx_show_class_proofs_generated_at');
            $table->index(['show_class_id', 'proofs_uploaded_at'], 'idx_show_class_proofs_uploaded_at');
            $table->index(['show_class_id', 'proofs_generated_at', 'proofs_uploaded_at'], 'idx_show_class_proofs_staleness');

            $table->index(['show_class_id', 'web_image_generated_at'], 'idx_show_class_web_image_generated_at');
            $table->index(['show_class_id', 'web_image_uploaded_at'], 'idx_show_class_web_image_uploaded_at');
            $table->index(['show_class_id', 'web_image_generated_at', 'web_image_uploaded_at'], 'idx_show_class_web_image_staleness');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
