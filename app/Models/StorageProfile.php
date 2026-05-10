<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageProfile extends Model
{
    use HasFactory;

    public const LEGACY_LOCAL_ID = 'legacy-local';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'string',
        'label' => 'string',
        'driver' => 'string',
        'bucket' => 'string',
        'region' => 'string',
        'endpoint' => 'string',
        'use_path_style' => 'boolean',
        'root' => 'string',
        'fingerprint' => 'string',
        'is_active' => 'boolean',
        'is_writable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class, 'storage_profile_id', 'id');
    }

    public function isLegacyLocal(): bool
    {
        return $this->id === self::LEGACY_LOCAL_ID;
    }

    public function envPrefix(): string
    {
        return 'PROFILE_'.strtoupper(str_replace('-', '_', $this->id));
    }

    public function toApiPayload(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'driver' => $this->driver,
            'bucket' => $this->bucket,
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'use_path_style' => $this->use_path_style,
            'root' => $this->root,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
