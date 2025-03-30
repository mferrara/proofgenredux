<?php

namespace App\Models;

use App\Proofgen\Utility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Show extends Model
{
    protected $table = 'shows';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ShowClass::class, 'show_id', 'id');
    }

    public function photos(): HasManyThrough
    {
        return $this->hasManyThrough(
            Photo::class,
            ShowClass::class,
            'show_id', // Foreign key on ShowClass table
            'show_class_id', // Foreign key on Photo table
            'id', // Local key on Show table
            'id' // Local key on ShowClass table
        );
    }

    public function getFullPathAttribute(): string
    {
        return config('proofgen.fullsize_home_dir') . '/' . $this->id;
    }

    public function getRelativePathAttribute(): string
    {
        return $this->id;
    }

    // Model events
    protected static function boot()
    {
        parent::boot();

        static::created(function ($model) {
            // Check the show directory for subdirectories, where each subdirectory is a ShowClass where it's 'id' is
            // the subdirectory name, and it's name is the subdirectory name and import them (ie: create ShowClass
            // records where they don't exist)
            $class_directories = Utility::getDirectoriesOfPath($model->relative_path);
            foreach($class_directories as $class_folder) {
                $class_folder = str_replace($model->id.'/', '', $class_folder);
                if( ! $model->hasClass($class_folder)) {
                    // If the class doesn't exist, create it
                    $class = $model->addClass($class_folder);
                }
            }
        });
    }

    public function hasClass(string $class_name): bool
    {
        $class = $this->classes()->where('id', $this->id.'_'.$class_name)->first();
        if ($class) {
            return true;
        }
        return false;
    }

    public function addClass(string $class_folder)
    {
        return $this->classes()->create([
            'id' => $this->id.'_'.$class_folder,
            'name' => $class_folder,
        ]);
    }
}
