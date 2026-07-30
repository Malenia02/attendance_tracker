<?php

namespace App\Models;

use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestLog extends Model
{
    use ImmutableAuditRecord;

    protected $table = 'leave_request_logs';

    protected $primaryKey = 'leave_request_log_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'leave_id',
        'from_status',
        'to_status',
        'remarks',
        'changed_by',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRecord::class, 'leave_id', 'leave_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
