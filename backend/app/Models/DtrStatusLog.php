<?php

namespace App\Models;

use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DtrStatusLog extends Model
{
    use ImmutableAuditRecord;

    protected $table = 'dtr_status_logs';

    protected $primaryKey = 'dtr_status_log_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'dtr_certification_id',
        'changed_by',
        'from_status',
        'to_status',
        'remarks',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    public function certification(): BelongsTo
    {
        return $this->belongsTo(
            DtrCertification::class,
            'dtr_certification_id',
            'dtr_certification_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
