<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelSchedule extends Model
{
    protected $table = 'personnel_schedules';

    protected $primaryKey = 'personnel_schedule_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'personnel_id',
        'schedule_id',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
            'user_id'
        );
    }
}