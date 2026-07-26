<?php

namespace App\Support;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PersonnelAccess
{
    private const GLOBAL_ROLES = ['Administrator', 'HR'];

    private const DEPARTMENT_ROLES = ['Supervisor', 'Encoder'];

    public static function scope(Builder $query, User $user): Builder
    {
        $user->loadMissing('personnel');

        if (in_array($user->user_role, self::GLOBAL_ROLES, true)) {
            return $query;
        }

        if (in_array($user->user_role, self::DEPARTMENT_ROLES, true)) {
            return $query->where(
                'department_id',
                $user->personnel?->department_id ?? -1
            );
        }

        return $query->whereKey($user->personnel_id ?? -1);
    }

    public static function canAccess(User $user, Personnel $personnel): bool
    {
        $user->loadMissing('personnel');

        if (in_array($user->user_role, self::GLOBAL_ROLES, true)) {
            return true;
        }

        if (in_array($user->user_role, self::DEPARTMENT_ROLES, true)) {
            return $user->personnel?->department_id !== null
                && (int) $user->personnel->department_id === (int) $personnel->department_id;
        }

        return $user->personnel_id !== null
            && (int) $user->personnel_id === (int) $personnel->personnel_id;
    }

    public static function canManageOthers(User $user): bool
    {
        return in_array(
            $user->user_role,
            [...self::GLOBAL_ROLES, ...self::DEPARTMENT_ROLES],
            true
        );
    }

    public static function hasGlobalAccess(User $user): bool
    {
        return in_array($user->user_role, self::GLOBAL_ROLES, true);
    }
}
