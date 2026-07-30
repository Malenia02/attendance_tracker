<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Personnel extends Model
{
    protected $table = 'personnel';

    protected $primaryKey = 'personnel_id';

    protected $fillable = [
        'employee_number',
        'biometric_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'sex',
        'personnel_type',
        'position_title',
        'department_id',
        'employment_start_date',
        'employment_end_date',
        'email',
        'contact_number',
        'address',
        'photo',
        'signature',
        'qr_login_code',
        'qr_valid_from',
        'qr_valid_until',
        'status',
    ];

    protected $hidden = [
        'qr_login_code',
        'photo',
        'signature',
    ];

    protected $casts = [
        'employment_start_date' => 'date',
        'employment_end_date' => 'date',
        'qr_valid_from' => 'date',
        'qr_valid_until' => 'date',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
            'department_id'
        );
    }

    public function user(): HasOne
    {
        return $this->hasOne(
            User::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(
            PersonnelSchedule::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(
            AttendanceRecord::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(
            TimeLog::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function qrScanLogs(): HasMany
    {
        return $this->hasMany(
            QrScanLog::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function leaveRecords(): HasMany
    {
        return $this->hasMany(
            LeaveRecord::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function dtrCertifications(): HasMany
    {
        return $this->hasMany(
            DtrCertification::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function getFullNameAttribute(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }
}
