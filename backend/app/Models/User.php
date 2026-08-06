<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $table = 'system_users';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'personnel_id',
        'username',
        'password_hash',
        'user_role',
        'status',
        'failed_login_attempts',
        'locked_until',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(
            Personnel::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function verifiedAttendances(): HasMany
    {
        return $this->hasMany(
            AttendanceRecord::class,
            'verified_by',
            'user_id'
        );
    }

    public function createdAttendances(): HasMany
    {
        return $this->hasMany(
            AttendanceRecord::class,
            'created_by',
            'user_id'
        );
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(
            ActivityLog::class,
            'user_id',
            'user_id'
        );
    }

    public function submittedAttendanceCorrectionRequests(): HasMany
    {
        return $this->hasMany(
            AttendanceCorrectionRequest::class,
            'submitted_by',
            'user_id'
        );
    }

    public function reviewedAttendanceCorrectionRequests(): HasMany
    {
        return $this->hasMany(
            AttendanceCorrectionRequest::class,
            'reviewed_by',
            'user_id'
        );
    }

    public function submittedDtrReopenRequests(): HasMany
    {
        return $this->hasMany(DtrReopenRequest::class, 'requested_by', 'user_id');
    }

    public function reviewedDtrReopenRequests(): HasMany
    {
        return $this->hasMany(DtrReopenRequest::class, 'reviewed_by', 'user_id');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(
            UserAccessToken::class,
            'user_id',
            'user_id'
        );
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(
            UserNotification::class,
            'user_id',
            'user_id'
        );
    }
}
