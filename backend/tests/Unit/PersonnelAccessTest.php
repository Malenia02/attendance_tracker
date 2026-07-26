<?php

namespace Tests\Unit;

use App\Models\Personnel;
use App\Models\User;
use App\Support\PersonnelAccess;
use Tests\TestCase;

class PersonnelAccessTest extends TestCase
{
    public function test_personnel_user_can_only_access_their_own_record(): void
    {
        $user = $this->user('Personnel', 10, 1);

        $this->assertTrue(PersonnelAccess::canAccess($user, $this->personnel(10, 1)));
        $this->assertFalse(PersonnelAccess::canAccess($user, $this->personnel(11, 1)));
    }

    public function test_supervisor_and_encoder_are_limited_to_their_department(): void
    {
        foreach (['Supervisor', 'Encoder'] as $role) {
            $user = $this->user($role, 20, 7);

            $this->assertTrue(PersonnelAccess::canAccess($user, $this->personnel(21, 7)));
            $this->assertFalse(PersonnelAccess::canAccess($user, $this->personnel(22, 8)));
        }
    }

    public function test_administrator_and_hr_have_global_access(): void
    {
        foreach (['Administrator', 'HR'] as $role) {
            $user = $this->user($role, null, null);

            $this->assertTrue(PersonnelAccess::canAccess($user, $this->personnel(99, 100)));
        }
    }

    private function user(string $role, ?int $personnelId, ?int $departmentId): User
    {
        $user = new User([
            'personnel_id' => $personnelId,
            'username' => strtolower($role),
            'user_role' => $role,
            'status' => 'Active',
        ]);

        if ($personnelId !== null) {
            $user->setRelation('personnel', $this->personnel($personnelId, $departmentId));
        } else {
            $user->setRelation('personnel', null);
        }

        return $user;
    }

    private function personnel(int $id, ?int $departmentId): Personnel
    {
        $personnel = new Personnel(['department_id' => $departmentId]);
        $personnel->setAttribute('personnel_id', $id);

        return $personnel;
    }
}
