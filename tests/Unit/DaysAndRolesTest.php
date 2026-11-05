<?php

namespace Tests\Unit;

use App\Support\Days;
use App\Support\Roles;
use Tests\TestCase;

class DaysAndRolesTest extends TestCase
{
    public function test_days_helpers(): void
    {
        $this->assertSame('2026-11-05', Days::today());
        $this->assertSame('2026-11-06', Days::add('2026-11-05', 1));
        $this->assertSame('2026-10-31', Days::add('2026-11-05', -5));
        $this->assertSame('2026-11-02', Days::key(Days::mondayOf(Days::fromKey('2026-11-08')))); // a Sunday belongs to the week of Monday 2
        $this->assertSame('2026-11-05', Days::keyOf('2026-11-05 12:00:00.000'));
        $this->assertSame('', Days::keyOf(null));
        $this->assertSame('2026-11-05 12:00:00.000', Days::toDb('2026-11-05'));
    }

    public function test_old_role_spellings_are_understood(): void
    {
        $this->assertSame('manager', Roles::normalize('Manger'));
        $this->assertSame('admin', Roles::normalize('admain'));
        $this->assertSame('super_admin', Roles::normalize('super_admain'));
        $this->assertSame('super_admin', Roles::normalize('Super Admin'));
        $this->assertSame('employee', Roles::normalize('something odd'));
        $this->assertSame('employee', Roles::normalize(null));
    }

    public function test_who_may_do_what(): void
    {
        $this->assertTrue(Roles::isAdmin('admin'));
        $this->assertTrue(Roles::isAdmin('super_admin'));
        $this->assertFalse(Roles::isAdmin('manager'), 'a manager is not an admin');
        $this->assertTrue(Roles::opensAdminSide('manager'));
        $this->assertFalse(Roles::opensAdminSide('responsible'));
        $this->assertTrue(Roles::isSuper('super_admin'));
        $this->assertFalse(Roles::isSuper('admin'));
        $this->assertTrue(Roles::isDoor('door'));
        foreach (['manager', 'admin', 'super_admin', 'door'] as $role) {
            $this->assertTrue(Roles::isProtected($role), $role);
        }
        $this->assertFalse(Roles::isProtected('employee'));
        $this->assertSame('/admin', Roles::home('manager'));
        $this->assertSame('/week', Roles::home('intern'));
    }
}
