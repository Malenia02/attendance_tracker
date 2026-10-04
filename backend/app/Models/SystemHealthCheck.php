<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SystemHealthCheck extends Model
{
    protected $table = 'system_health_checks';

    protected $primaryKey = 'check_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'check_key',
        'status',
        'last_success_at',
        'metadata',
    ];

    protected $casts = [
        'last_success_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function recordSuccess(string $key, array $metadata = []): self
    {
        return self::query()->updateOrCreate(
            ['check_key' => $key],
            [
                'status' => 'healthy',
                'last_success_at' => now(),
                'metadata' => $metadata ?: null,
            ]
        );
    }
}
