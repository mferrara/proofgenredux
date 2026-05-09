<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoIssue extends Model
{
    protected $table = 'photo_issues';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const TYPE_DUPLICATE_CONTENT = 'duplicate_content';

    public const TYPE_PROOF_COLLISION = 'proof_collision';

    public const TYPE_INVALID_NUMBERED_FILENAME = 'invalid_numbered_filename';

    public const TYPE_NEEDS_REVIEW = 'needs_review';

    public const TYPE_MISSING_ARCHIVE = 'missing_archive';

    public const TYPE_METADATA_MISMATCH = 'metadata_mismatch';

    public const TYPE_ARCHIVE_CONFLICT = 'archive_conflict';

    public const TYPE_ORPHAN_ORIGINAL = 'orphan_original';

    public const TYPE_MISSING_ORIGINAL = 'missing_original';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'incoming_size' => 'integer',
        'incoming_mtime' => 'datetime',
        'resolved_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function existingPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'existing_photo_id', 'id');
    }

    public function showClass(): BelongsTo
    {
        return $this->belongsTo(ShowClass::class, 'show_class_id', 'id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Issues whose resolution actions involve operating on a quarantined source file
     * (duplicate_content, proof_collision). Audit-discovered issues don't have one.
     */
    public function hasQuarantinedSource(): bool
    {
        return in_array($this->issue_type, [
            self::TYPE_DUPLICATE_CONTENT,
            self::TYPE_PROOF_COLLISION,
            self::TYPE_INVALID_NUMBERED_FILENAME,
            self::TYPE_NEEDS_REVIEW,
        ], true);
    }

    public function severityColor(): string
    {
        return match ($this->issue_type) {
            self::TYPE_PROOF_COLLISION,
            self::TYPE_MISSING_ORIGINAL,
            self::TYPE_ARCHIVE_CONFLICT => 'rose',
            self::TYPE_DUPLICATE_CONTENT,
            self::TYPE_METADATA_MISMATCH,
            self::TYPE_INVALID_NUMBERED_FILENAME,
            self::TYPE_NEEDS_REVIEW => 'amber',
            self::TYPE_MISSING_ARCHIVE,
            self::TYPE_ORPHAN_ORIGINAL => 'sky',
            default => 'zinc',
        };
    }
}
