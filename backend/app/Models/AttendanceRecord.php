<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';

    protected $primaryKey = 'attendance_id';

    protected $fillable = [
        'personnel_id',
        'schedule_id',
        'attendance_date',
        'morning_time_in',
        'morning_time_out',
        'afternoon_time_in',
        'afternoon_time_out',
        'overtime_time_in',
        'overtime_time_out',
        'attendance_status',
        'total_work_minutes',
        'late_minutes',
        'undertime_minutes',
        'overtime_minutes',
        'remarks',
        'record_source',
        'is_verified',
        'verified_by',
        'verified_at',
        'created_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'morning_time_in' => 'datetime',
        'morning_time_out' => 'datetime',
        'afternoon_time_in' => 'datetime',
        'afternoon_time_out' => 'datetime',
        'overtime_time_in' => 'datetime',
        'overtime_time_out' => 'datetime',
        'total_work_minutes' => 'integer',
        'late_minutes' => 'integer',
        'undertime_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(
            Personnel::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(
            WorkSchedule::class,
            'schedule_id',
            'schedule_id'
        );
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by',
            'user_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
            'user_id'
        );
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(
            TimeLog::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function qrScanLogs(): HasMany
    {
        return $this->hasMany(
            QrScanLog::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(
            AttendanceChangeLog::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(
            AttendanceCorrectionRequest::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function getIsCompleteAttribute(): bool
    {
        return ! empty($this->morning_time_in)
            && ! empty($this->morning_time_out)
            && ! empty($this->afternoon_time_in)
            && ! empty($this->afternoon_time_out);
    }

    public function getNextActionAttribute(): ?string
    {
        if (! $this->morning_time_in) {
            return 'morning_time_in';
        }

        if (! $this->morning_time_out) {
            return 'morning_time_out';
        }

        if (! $this->afternoon_time_in) {
            return 'afternoon_time_in';
        }

        if (! $this->afternoon_time_out) {
            return 'afternoon_time_out';
        }

        return null;
    }
}
