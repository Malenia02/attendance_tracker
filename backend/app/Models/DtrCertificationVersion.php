<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DtrCertificationVersion extends Model
{
    protected $table = 'dtr_certification_versions';

    protected $primaryKey = 'dtr_certification_version_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'dtr_certification_id',
        'version_number',
        'prepared_by',
        'certified_by',
        'archived_by',
        'prepared_at',
        'certified_at',
        'archived_at',
        'archive_reason',
        'certified_snapshot',
        'certified_hash',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'prepared_at' => 'datetime',
        'certified_at' => 'datetime',
        'archived_at' => 'datetime',
        'certified_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(
            'Archived DTR certification versions are immutable.'
        ));
        static::deleting(fn () => throw new LogicException(
            'Archived DTR certification versions cannot be deleted.'
        ));
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(
            DtrCertification::class,
            'dtr_certification_id',
            'dtr_certification_id'
        );
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'user_id');
    }
}
