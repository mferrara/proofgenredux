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
        Schema::create('photo_metadata', function (Blueprint $table) {
            $table->string('photo_id')->primary();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->string('orientation', 2)->nullable();
            $table->string('aspect_ratio', 10)->nullable();
            $table->string('shutter_speed', 15)->nullable();
            $table->string('aperture', 15)->nullable();
            $table->string('iso', 15)->nullable();
            $table->string('exposure_bias', 15)->nullable();
            $table->string('max_aperture', 15)->nullable();
            $table->string('focal_length', 15)->nullable();
            $table->string('artist')->nullable();
            $table->string('camera_make')->nullable();
            $table->string('camera_model')->nullable();
            $table->string('megapixels', 6)->nullable();
            $table->timestamp('exif_timestamp')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_metadata');
    }
};
