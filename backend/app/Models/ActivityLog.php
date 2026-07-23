<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $primaryKey = 'activity_log_id';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'activity_type',
        'description',
        'entity_type',
        'entity_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'entity_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'user_id'
        );
    }
}