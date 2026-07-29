<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'departments';

    protected $primaryKey = 'department_id';

    protected $fillable = [
        'department_code',
        'department_name',
        'office_location',
        'latitude',
        'longitude',
        'allowed_radius_meters',
        'status',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'allowed_radius_meters' => 'integer',
    ];

    public function personnel(): HasMany
    {
        return $this->hasMany(
            Personnel::class,
            'department_id',
            'department_id'
        );
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(
            Holiday::class,
            'department_id',
            'department_id'
        );
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(
            AttendanceQrToken::class,
            'department_id',
            'department_id'
        );
    }
}
