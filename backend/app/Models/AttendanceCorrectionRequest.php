<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrectionRequest extends Model
{
    protected $primaryKey = 'attendance_correction_request_id';

    protected $fillable = [
        'attendance_id',
        'personnel_id',
        'submitted_by',
        'attendance_date',
        'missing_field',
        'proposed_time',
        'reason',
        'request_status',
        'pending_key',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceRecord::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(
            Personnel::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'submitted_by',
            'user_id'
        );
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by',
            'user_id'
        );
    }
}
