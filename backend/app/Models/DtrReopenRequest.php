<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DtrReopenRequest extends Model
{
    protected $table = 'dtr_reopen_requests';

    protected $primaryKey = 'dtr_reopen_request_id';

    protected $fillable = [
        'dtr_certification_id',
        'requested_by',
        'reviewed_by',
        'reason',
        'affected_dates',
        'request_status',
        'review_remarks',
        'reviewed_at',
        'pending_key',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected $casts = [
        'affected_dates' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function certification(): BelongsTo
    {
        return $this->belongsTo(
            DtrCertification::class,
            'dtr_certification_id',
            'dtr_certification_id'
        );
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }
}
