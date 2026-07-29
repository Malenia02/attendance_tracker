<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableAuditRecord
{
    protected static function bootImmutableAuditRecord(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit records are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit records cannot be deleted.');
        });
    }
}
