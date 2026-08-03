<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserNotification extends Model
{
    protected $table = 'user_notifications';

    protected $primaryKey = 'notification_id';

    protected $fillable = [
        'user_id',
        'notification_key',
        'notification_type',
        'title',
        'message',
        'severity',
        'action_url',
        'content_hash',
        'metadata',
        'read_at',
        'resolved_at',
    ];

    protected $hidden = [
        'notification_key',
        'content_hash',
        'metadata',
        'user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
