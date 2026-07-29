<?php

namespace App\Models;

use App\Models\Concerns\ImmutableAuditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use ImmutableAuditRecord;

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
        'request_id',
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
