<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\PersonnelAccess;
use Illuminate\Auth\Access\Response;

final class AttendanceRecordPolicy
{
    public function view(User $user, AttendanceRecord $attendance): bool
    {
        return $attendance->personnel
            && PersonnelAccess::canAccess($user, $attendance->personnel);
    }

    public function verify(User $user, AttendanceRecord $attendance): Response
    {
        if (! in_array($user->user_role, ['Administrator', 'HR', 'Supervisor'], true)) {
            return Response::deny('You do not have permission to verify attendance records.');
        }

        if (! $this->view($user, $attendance)) {
            return Response::deny('This attendance record is outside your assigned office scope.');
        }

        if (
            $user->user_role !== 'Administrator'
            && (int) $user->personnel_id === (int) $attendance->personnel_id
        ) {
            return Response::deny(
                'You cannot verify your own attendance record. A different authorized reviewer is required.'
            );
        }

        return Response::allow();
    }
}
