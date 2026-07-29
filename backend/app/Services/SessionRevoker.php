<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SessionRevoker
{
    public function revokeFor(User $user): void
    {
        $user->accessTokens()->delete();

        if (
            config('session.driver') === 'database'
            && Schema::hasTable((string) config('session.table', 'sessions'))
        ) {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        }
    }
}
