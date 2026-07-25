<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrScanLog extends Model
{
    protected $table = 'qr_scan_logs';

    protected $primaryKey = 'qr_scan_id';

    public $timestamps = false;

    protected $fillable = [
        'qr_token_id',
        'personnel_id',
        'attendance_id',
        'scan_action',
        'scanned_at',
        'latitude',
        'longitude',
        'distance_from_office_meters',
        'ip_address',
        'user_agent',
        'device_identifier',
        'scanned_by',
        'scan_status',
        'message',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'distance_from_office_meters' => 'decimal:2',
    ];

    public function qrToken(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceQrToken::class,
            'qr_token_id',
            'qr_token_id'
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

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceRecord::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'scanned_by',
            'user_id'
        );
    }
}
