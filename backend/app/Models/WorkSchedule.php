<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    protected $table = 'work_schedules';

    protected $primaryKey = 'schedule_id';

    protected $fillable = [
        'schedule_name',
        'morning_start',
        'morning_end',
        'afternoon_start',
        'afternoon_end',
        'morning_time_in_start',
        'morning_time_in_end',
        'morning_time_out_start',
        'morning_time_out_end',
        'afternoon_time_in_start',
        'afternoon_time_in_end',
        'afternoon_time_out_start',
        'afternoon_time_out_end',
        'grace_period_minutes',
        'required_minutes_per_day',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
        'status',
    ];

    protected $casts = [
        'grace_period_minutes' => 'integer',
        'required_minutes_per_day' => 'integer',
        'monday' => 'boolean',
        'tuesday' => 'boolean',
        'wednesday' => 'boolean',
        'thursday' => 'boolean',
        'friday' => 'boolean',
        'saturday' => 'boolean',
        'sunday' => 'boolean',
    ];

    public function personnelSchedules(): HasMany
    {
        return $this->hasMany(
            PersonnelSchedule::class,
            'schedule_id',
            'schedule_id'
        );
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(
            AttendanceRecord::class,
            'schedule_id',
            'schedule_id'
        );
    }
}