<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture richer EXIF for forensic comparison: lens/body serials, sub-second
 * capture time, image unique id, GPS, and shooting parameters that help
 * operators tell near-duplicates apart when sha1 alone isn't enough (e.g.
 * a slightly recompressed copy of the same frame).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_metadata', function (Blueprint $table) {
            $table->string('subsec_time_original', 8)->nullable()->after('exif_timestamp');
            $table->string('lens_make')->nullable()->after('camera_model');
            $table->string('lens_model')->nullable()->after('lens_make');
            $table->string('body_serial_number', 64)->nullable()->after('lens_model');
            $table->string('lens_serial_number', 64)->nullable()->after('body_serial_number');
            $table->string('image_unique_id', 64)->nullable()->after('lens_serial_number');
            $table->decimal('gps_latitude', 10, 7)->nullable()->after('image_unique_id');
            $table->decimal('gps_longitude', 10, 7)->nullable()->after('gps_latitude');
            $table->decimal('gps_altitude', 8, 2)->nullable()->after('gps_longitude');
            $table->string('software')->nullable()->after('gps_altitude');
            $table->string('color_space', 16)->nullable()->after('software');
            $table->string('white_balance', 16)->nullable()->after('color_space');
            $table->string('exposure_program', 24)->nullable()->after('white_balance');
            $table->string('metering_mode', 24)->nullable()->after('exposure_program');
            $table->string('flash', 32)->nullable()->after('metering_mode');
            $table->decimal('focal_length_35mm', 6, 1)->nullable()->after('flash');

            $table->index('body_serial_number', 'idx_photo_metadata_body_serial');
            $table->index('image_unique_id', 'idx_photo_metadata_image_unique_id');
        });
    }

    public function down(): void
    {
        Schema::table('photo_metadata', function (Blueprint $table) {
            $table->dropIndex('idx_photo_metadata_body_serial');
            $table->dropIndex('idx_photo_metadata_image_unique_id');
            $table->dropColumn([
                'subsec_time_original',
                'lens_make',
                'lens_model',
                'body_serial_number',
                'lens_serial_number',
                'image_unique_id',
                'gps_latitude',
                'gps_longitude',
                'gps_altitude',
                'software',
                'color_space',
                'white_balance',
                'exposure_program',
                'metering_mode',
                'flash',
                'focal_length_35mm',
            ]);
        });
    }
};
