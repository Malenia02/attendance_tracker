<?php

namespace App\Models;

use App\Support\DtrPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DtrCertification extends Model
{
    protected $table = 'dtr_certifications';

    protected $primaryKey = 'dtr_certification_id';

    protected $fillable = [
        'personnel_id',
        'dtr_year',
        'dtr_month',
        'dtr_period',
        'version_number',
        'prepared_by',
        'certified_by',
        'prepared_at',
        'certified_at',
        'certification_status',
        'remarks',
        'certified_snapshot',
        'certified_hash',
    ];

    public function scopeCoveringDate(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('dtr_year', $date->year)
            ->where('dtr_month', $date->month)
            ->whereIn('dtr_period', DtrPeriod::periodsCoveringDate($date));
    }

    protected $casts = [
        'dtr_year' => 'integer',
        'dtr_month' => 'integer',
        'version_number' => 'integer',
        'prepared_at' => 'datetime',
        'certified_at' => 'datetime',
        'certified_snapshot' => 'array',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(
            Personnel::class,
            'personnel_id',
            'personnel_id'
        );
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'prepared_by',
            'user_id'
        );
    }

    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'certified_by',
            'user_id'
        );
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(
            DtrStatusLog::class,
            'dtr_certification_id',
            'dtr_certification_id'
        )->orderByDesc('created_at');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(
            DtrCertificationVersion::class,
            'dtr_certification_id',
            'dtr_certification_id'
        )->orderByDesc('version_number');
    }

    public function reopenRequests(): HasMany
    {
        return $this->hasMany(
            DtrReopenRequest::class,
            'dtr_certification_id',
            'dtr_certification_id'
        )->orderByDesc('created_at');
    }
}
