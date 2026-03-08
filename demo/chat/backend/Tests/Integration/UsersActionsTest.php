<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;

/**
 * Integration tests for UsersActions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UsersActionsTest extends IntegrationTestCase
{
    public function testRegisterCreatesUser(): void
    {
        $token = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($token);

        $this->assertNotNull($user->id);
        $this->assertStringStartsWith('User', $user->name);
        $this->assertSame($token, $user->sessionToken);
    }

    public function testRegisterInvalidTokenThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid session token');

        Hilos::$db->users->actions->register('short');
    }

    public function testRegisterDuplicateTokenThrows(): void
    {
        $token = bin2hex(random_bytes(16));
        Hilos::$db->users->actions->register($token);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        Hilos::$db->users->actions->register($token);
    }
}
