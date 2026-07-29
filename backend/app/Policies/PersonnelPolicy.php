<?php

namespace App\Policies;

use App\Models\Personnel;
use App\Models\User;
use App\Support\PersonnelAccess;

final class PersonnelPolicy
{
    public function view(User $user, Personnel $personnel): bool
    {
        return PersonnelAccess::canAccess($user, $personnel);
    }

    public function correctAttendance(User $user, Personnel $personnel): bool
    {
        return in_array($user->user_role, ['Administrator', 'HR'], true)
            && PersonnelAccess::canAccess($user, $personnel);
    }

    public function manageQr(User $user, Personnel $personnel): bool
    {
        return in_array($user->user_role, ['Administrator', 'HR'], true)
            && PersonnelAccess::canAccess($user, $personnel);
    }
}
