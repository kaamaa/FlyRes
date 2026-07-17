<?php
namespace App\Tests\Entities;

use App\Entities\Users;
use PHPUnit\Framework\TestCase;

class UsersTest extends TestCase
{
    public function testIsNewlyLockedTrueOnlyOnUnlockedToLockedTransition(): void
    {
        $this->assertTrue(Users::isNewlyLocked(false, true));
    }

    public function testIsNewlyLockedFalseWhenAlreadyLocked(): void
    {
        $this->assertFalse(Users::isNewlyLocked(true, true));
    }

    public function testIsNewlyLockedFalseWhenUnlocked(): void
    {
        $this->assertFalse(Users::isNewlyLocked(false, false));
    }

    public function testIsNewlyLockedFalseOnUnlockTransition(): void
    {
        $this->assertFalse(Users::isNewlyLocked(true, false));
    }
}
