<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for UserActions (item-level).
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UserActionsTest extends IntegrationTestCase
{
    /**
     * Rename with valid name updates user in database.
     *
     * @throws HilosException On database error
     */
    public function testRenameSucceeds(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $userId = $user->id;
        $this->assertNotNull($userId);

        $dbUser = Hilos::$db->users[$userId];
        $dbUser->actions->rename('Alice');

        $refreshed = Hilos::$db->users[$userId];
        $this->assertSame('Alice', $refreshed->name);
    }

    /**
     * Rename accepts the maximum configured display-name length.
     *
     * @throws HilosException On database error
     */
    public function testRenameAcceptsSixtyFourCharacters(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $userId = $user->id;
        $this->assertNotNull($userId);

        $name = str_repeat('x', 64);
        Hilos::$db->users[$userId]->actions->rename($name);

        $this->assertSame($name, Hilos::$db->users[$userId]?->name);
    }

    /**
     * Rename with empty/whitespace-only name throws EmptyValueException.
     *
     * @throws HilosException On database error
     */
    public function testRenameEmptyThrows(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $dbUser = Hilos::$db->users[$user->id];

        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage('cannot be empty');

        $dbUser->actions->rename('   ');
    }

    /**
     * Rename with a one-character name throws ValueTooShortException.
     *
     * @throws HilosException On database error
     */
    public function testRenameTooShortThrows(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $dbUser = Hilos::$db->users[$user->id];

        $this->expectException(ValueTooShortException::class);
        $this->expectExceptionMessage('too short');

        $dbUser->actions->rename('x');
    }

    /**
     * Rename with name exceeding max length throws ValueTooLongException.
     *
     * @throws HilosException On database error
     */
    public function testRenameTooLongThrows(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $dbUser = Hilos::$db->users[$user->id];

        $this->expectException(ValueTooLongException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $dbUser->actions->rename(str_repeat('x', 65));
    }

    /**
     * Rename with same name performs no-op (no DB update).
     *
     * @throws HilosException On database error
     */
    public function testRenameSameNameNoOp(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $originalName = $user->name;
        $dbUser = Hilos::$db->users[$user->id];

        $dbUser->actions->rename($originalName);

        $refreshed = Hilos::$db->users[$user->id];
        $this->assertSame($originalName, $refreshed->name);
    }

    /**
     * setAdmin grants then revokes the admin flag.
     *
     * @throws HilosException On database error
     */
    public function testSetAdminGrantsThenRevokes(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $userId = $user->id;
        $this->assertNotNull($userId);
        $this->assertFalse($user->admin);

        Hilos::$db->users[$userId]->actions->setAdmin(true);
        $this->assertTrue(Hilos::$db->users[$userId]?->admin);

        Hilos::$db->users[$userId]->actions->setAdmin(false);
        $this->assertFalse(Hilos::$db->users[$userId]?->admin);
    }

    /**
     * setAdmin with the same value performs no-op.
     *
     * @throws HilosException On database error
     */
    public function testSetAdminSameValueNoOp(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);
        $userId = $user->id;

        Hilos::$db->users[$userId]->actions->setAdmin(false);

        $this->assertFalse(Hilos::$db->users[$userId]?->admin);
    }
}
