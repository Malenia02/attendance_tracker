<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DtrCertification extends Model
{
    protected $table = 'dtr_certifications';

    protected $primaryKey = 'dtr_certification_id';

    protected $fillable = [
        'personnel_id',
        'dtr_year',
        'dtr_month',
        'prepared_by',
        'certified_by',
        'prepared_at',
        'certified_at',
        'certification_status',
        'remarks',
    ];

    protected $casts = [
        'dtr_year' => 'integer',
        'dtr_month' => 'integer',
        'prepared_at' => 'datetime',
        'certified_at' => 'datetime',
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
}