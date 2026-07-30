<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveRecord extends Model
{
    protected $table = 'leave_records';

    protected $primaryKey = 'leave_id';

    protected $fillable = [
        'personnel_id',
        'request_number',
        'submitted_by',
        'leave_type',
        'day_part',
        'date_from',
        'date_to',
        'total_days',
        'reason',
        'supporting_document',
        'approval_status',
        'review_remarks',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $hidden = [
        'supporting_document',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'total_days' => 'decimal:2',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(
            Personnel::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by',
            'user_id'
        );
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'submitted_by',
            'user_id'
        );
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by',
            'user_id'
        );
    }

    public function history(): HasMany
    {
        return $this->hasMany(
            LeaveRequestLog::class,
            'leave_id',
            'leave_id'
        )->latest('created_at');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(
            AttendanceRecord::class,
            'leave_record_id',
            'leave_id'
        );
    }
}
