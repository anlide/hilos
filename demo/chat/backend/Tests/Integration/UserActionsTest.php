<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Runtime\Exception\RtBaseException;

/**
 * Integration tests for UserActions (item-level).
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UserActionsTest extends IntegrationTestCase
{
    /**
     * Rename with valid name updates user in database.
     */
    public function testRenameSucceeds(): void
    {
        $token = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($token);
        $userId = $user->id;
        $this->assertNotNull($userId);

        $dbUser = Hilos::$db->users[$userId];
        $dbUser->actions->rename('Alice');

        $refreshed = Hilos::$db->users[$userId];
        $this->assertSame('Alice', $refreshed->name);
    }

    /**
     * Rename with empty/whitespace-only name throws RtBaseException.
     */
    public function testRenameEmptyThrows(): void
    {
        $token = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($token);
        $dbUser = Hilos::$db->users[$user->id];

        $this->expectException(RtBaseException::class);
        $this->expectExceptionMessage('cannot be empty');

        $dbUser->actions->rename('   ');
    }

    /**
     * Rename with name exceeding max length throws RtBaseException.
     */
    public function testRenameTooLongThrows(): void
    {
        $token = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($token);
        $dbUser = Hilos::$db->users[$user->id];

        $this->expectException(RtBaseException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $dbUser->actions->rename(str_repeat('x', 65));
    }

    /**
     * Rename with same name performs no-op (no DB update).
     */
    public function testRenameSameNameNoOp(): void
    {
        $token = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($token);
        $originalName = $user->name;
        $dbUser = Hilos::$db->users[$user->id];

        $dbUser->actions->rename($originalName);

        $refreshed = Hilos::$db->users[$user->id];
        $this->assertSame($originalName, $refreshed->name);
    }
}
