<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeLog extends Model
{
    protected $table = 'time_logs';

    protected $primaryKey = 'time_log_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'personnel_id',
        'attendance_id',
        'qr_scan_id',
        'log_datetime',
        'log_type',
        'log_source',
        'ip_address',
        'device_identifier',
        'created_by',
    ];

    protected $casts = [
        'log_datetime' => 'datetime',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(
            Personnel::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceRecord::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function qrScan(): BelongsTo
    {
        return $this->belongsTo(
            QrScanLog::class,
            'qr_scan_id',
            'qr_scan_id'
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
}