<?php

namespace App\Models;

use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrScanLog extends Model
{
    use ImmutableAuditRecord;

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
        'location_accuracy_meters',
        'position_recorded_at',
        'distance_from_office_meters',
        'office_network_id',
        'location_verification_method',
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
        'location_accuracy_meters' => 'decimal:2',
        'position_recorded_at' => 'datetime',
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

    public function officeNetwork(): BelongsTo
    {
        return $this->belongsTo(
            OfficeNetwork::class,
            'office_network_id',
            'office_network_id'
        );
    }
}
