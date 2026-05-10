<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationInventory extends Model
{
    public const CONTENT_PROOF_THM = 'proof_thm';

    public const CONTENT_PROOF_STD = 'proof_std';

    public const CONTENT_WEB_IMAGE = 'web_image';

    public const CONTENT_HIGH_RES_IMAGE = 'high_res_image';

    public const STATUS_DISCOVERED = 'discovered';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COPIED = 'copied';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected $table = 'migration_inventory';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'source_size_bytes' => 'integer',
        'source_mtime' => 'datetime',
        'last_attempt_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'last_attempt_at' => now(),
        ])->save();
    }
}
