<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Random\RandomException;

/**
 * Integration tests for UsersActions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UsersActionsTest extends IntegrationTestCase
{
    /**
     * Register with valid token creates user with auto-generated name.
     *
     * @throws RandomException On random bytes generation error
     * @throws HilosException On database error
     */
    public function testRegisterCreatesUser(): void
    {
        $token = bin2hex(random_bytes(16));
        $user = Hilos::$db->users->actions->register($token);

        $this->assertNotNull($user->id);
        $this->assertStringStartsWith('User', $user->name);
        $this->assertSame($token, $user->sessionToken);
    }

    /**
     * Register with invalid token format throws InvalidFormatException.
     *
     * @throws HilosException On database error
     */
    public function testRegisterInvalidTokenThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Invalid session token');

        Hilos::$db->users->actions->register('short');
    }

    /**
     * Register with existing token throws DuplicateValueException.
     *
     * @throws RandomException On random bytes generation error
     * @throws HilosException On database error
     */
    public function testRegisterDuplicateTokenThrows(): void
    {
        $token = bin2hex(random_bytes(16));
        Hilos::$db->users->actions->register($token);

        $this->expectException(DuplicateValueException::class);
        $this->expectExceptionMessage('already exists');
        Hilos::$db->users->actions->register($token);
    }
}
