<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeNetwork extends Model
{
    protected $table = 'office_networks';

    protected $primaryKey = 'office_network_id';

    protected $fillable = [
        'department_id',
        'network_name',
        'ip_address',
        'verified_at',
        'expires_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(QrScanLog::class, 'office_network_id', 'office_network_id');
    }
}
