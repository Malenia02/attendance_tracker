<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceChangeLog extends Model
{
    protected $table = 'attendance_change_logs';

    protected $primaryKey = 'attendance_change_log_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'attendance_id',
        'changed_by',
        'action_type',
        'old_values',
        'new_values',
        'reason',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceRecord::class,
            'attendance_id',
            'attendance_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'changed_by',
            'user_id'
        );
    }
}