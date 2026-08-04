<?php

namespace App\Models;

use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelActivationLog extends Model
{
    use ImmutableAuditRecord;

    protected $table = 'personnel_activation_logs';

    protected $primaryKey = 'personnel_activation_log_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'personnel_id',
        'changed_by',
        'from_status',
        'to_status',
        'reason',
        'readiness_snapshot',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected $casts = [
        'readiness_snapshot' => 'array',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'personnel_id', 'personnel_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
