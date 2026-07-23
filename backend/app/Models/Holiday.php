<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    protected $table = 'holidays';

    protected $primaryKey = 'holiday_id';

    protected $fillable = [
        'holiday_date',
        'holiday_name',
        'holiday_type',
        'scope',
        'department_id',
        'description',
        'created_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
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
}