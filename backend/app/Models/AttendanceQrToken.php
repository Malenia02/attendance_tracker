<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceQrToken extends Model
{
    protected $table = 'attendance_qr_tokens';

    protected $primaryKey = 'qr_token_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'department_id',
        'token_hash',
        'purpose',
        'valid_from',
        'expires_at',
        'used_count',
        'maximum_uses',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'expires_at' => 'datetime',
        'used_count' => 'integer',
        'maximum_uses' => 'integer',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
            'department_id'
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

    public function scanLogs(): HasMany
    {
        return $this->hasMany(
            QrScanLog::class,
            'qr_token_id',
            'qr_token_id'
        );
    }

    public function getIsExpiredAttribute(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function getIsValidAttribute(): bool
    {
        return $this->is_active
            && now()->between($this->valid_from, $this->expires_at);
    }
}