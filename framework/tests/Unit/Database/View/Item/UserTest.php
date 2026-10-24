<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\View\Item;

use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\View\Item\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testViewConvertsActivityAndPreservesObjectStorageAndSerializationFlags(): void
    {
        foreach ([[null, null], ['1970-01-01 00:00:00', 0], ['2026-09-30 10:00:00', 1790762400000]] as [$stored, $moment]) {
            $object = ObjectUser::fromEntity(EntityUser::fromRow([
                'id' => 7,
                'name' => 'Ann',
                'admin' => true,
                'last_activity' => $stored,
                'theme_pick_initialized' => false,
            ]));
            $user = new User($object);

            $this->assertSame($stored, $object->lastActivity);
            $this->assertSame($stored, $object->toArray()[ObjectUser::lastActivity]);
            $this->assertSame($moment, $user->lastActivity);
            $expected = $object->toArray();
            $expected[ObjectUser::lastActivity] = $moment;
            $this->assertSame($expected, $user->toArray());
            $this->assertSame($expected, $user->toArray(false, false, true, true, true));
            unset($expected[ObjectUser::id]);
            $this->assertSame($expected, $user->toArray(false, false));
        }
    }
}
