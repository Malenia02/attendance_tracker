<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRecord extends Model
{
    protected $table = 'leave_records';

    protected $primaryKey = 'leave_id';

    protected $fillable = [
        'personnel_id',
        'leave_type',
        'date_from',
        'date_to',
        'total_days',
        'reason',
        'approval_status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'total_days' => 'decimal:2',
        'approved_at' => 'datetime',
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
}
