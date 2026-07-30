<?php

namespace App\Policies;

use App\Models\LeaveRecord;
use App\Models\User;

class LeaveRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === 'Active';
    }

    public function view(User $user, LeaveRecord $leaveRecord): bool
    {
        if (in_array($user->user_role, ['Administrator', 'HR'], true)) {
            return true;
        }

        if ($user->user_role === 'Supervisor') {
            $user->loadMissing('personnel');
            $leaveRecord->loadMissing('personnel');

            return $user->personnel?->department_id !== null
                && (int) $user->personnel->department_id
                    === (int) $leaveRecord->personnel?->department_id;
        }

        return $user->personnel_id !== null
            && (int) $user->personnel_id === (int) $leaveRecord->personnel_id;
    }

    public function create(User $user): bool
    {
        return $user->status === 'Active' && $user->personnel_id !== null;
    }

    public function review(User $user, LeaveRecord $leaveRecord): bool
    {
        if ($leaveRecord->approval_status !== 'Pending') {
            return false;
        }

        if ($user->user_role === 'Administrator') {
            return true;
        }

        if ($user->personnel_id !== null
            && (int) $user->personnel_id === (int) $leaveRecord->personnel_id) {
            return false;
        }

        if ($user->user_role === 'HR') {
            return true;
        }

        if ($user->user_role !== 'Supervisor') {
            return false;
        }

        $user->loadMissing('personnel');
        $leaveRecord->loadMissing('personnel');

        return $user->personnel?->department_id !== null
            && (int) $user->personnel->department_id
                === (int) $leaveRecord->personnel?->department_id;
    }

    public function cancel(User $user, LeaveRecord $leaveRecord): bool
    {
        if (! in_array($leaveRecord->approval_status, ['Pending', 'Approved'], true)) {
            return false;
        }

        if (in_array($user->user_role, ['Administrator', 'HR'], true)) {
            return true;
        }

        return $user->personnel_id !== null
            && (int) $user->personnel_id === (int) $leaveRecord->personnel_id;
    }

    public function document(User $user, LeaveRecord $leaveRecord): bool
    {
        return $this->view($user, $leaveRecord);
    }
}
