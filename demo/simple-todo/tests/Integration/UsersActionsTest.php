<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Integration;

use Demo\SimpleTodo\Hilos;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for UsersActions and the durable session lookup.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UsersActionsTest extends IntegrationTestCase
{
    /**
     * Register with a valid token creates a user with an auto-generated name.
     *
     * @throws HilosException On database error
     */
    public function testRegisterCreatesUser(): void
    {
        $token = RandomHelper::hex(16);
        $user = Hilos::$db->users->actions->register($token);

        $this->assertNotNull($user->id);
        $this->assertStringStartsWith('User', $user->name);
        $this->assertSame($token, $user->sessionToken);
    }

    /**
     * findBySession returns the same durable user registered for the token.
     *
     * @throws HilosException On database error
     */
    public function testFindBySessionReturnsRegisteredUser(): void
    {
        $token = RandomHelper::hex(16);
        $registered = Hilos::$db->users->actions->register($token);

        $found = Hilos::$db->users->findBySession($token);

        $this->assertNotNull($found);
        $this->assertSame($registered->id, $found->id);
        $this->assertSame($registered->name, $found->name);
    }

    /**
     * findBySession returns null for an unknown token.
     *
     * @throws HilosException On database error
     */
    public function testFindBySessionReturnsNullForUnknownToken(): void
    {
        $this->assertNull(Hilos::$db->users->findBySession(RandomHelper::hex(16)));
    }

    /**
     * Register with an invalid token format throws InvalidFormatException.
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
     * Register with an existing token throws DuplicateValueException.
     *
     * @throws HilosException On database error
     */
    public function testRegisterDuplicateTokenThrows(): void
    {
        $token = RandomHelper::hex(16);
        Hilos::$db->users->actions->register($token);

        $this->expectException(DuplicateValueException::class);
        $this->expectExceptionMessage('already exists');
        Hilos::$db->users->actions->register($token);
    }
}
