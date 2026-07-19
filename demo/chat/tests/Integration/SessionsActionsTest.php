<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the session model: anonymous creation, token lookup, and
 * the authenticate (bind) / logout (unbind) transitions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class SessionsActionsTest extends IntegrationTestCase
{
    /**
     * createAnonymous stores an anonymous session with an expiry and no user.
     *
     * @throws HilosException On database error
     */
    public function testCreateAnonymousCreatesAnonymousSession(): void
    {
        $token = RandomHelper::hex(16);
        $session = Hilos::$db->sessions->actions->createAnonymous($token);

        $this->assertNotNull($session->id);
        $this->assertSame($token, $session->token);
        $this->assertNull($session->userId);
        $this->assertNotNull($session->expiresAt);
    }

    /**
     * createAnonymous with an invalid token format throws InvalidFormatException.
     *
     * @throws HilosException On database error
     */
    public function testCreateAnonymousInvalidTokenThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Invalid session token');

        Hilos::$db->sessions->actions->createAnonymous('short');
    }

    /**
     * createAnonymous with an existing token throws DuplicateValueException.
     *
     * @throws HilosException On database error
     */
    public function testCreateAnonymousDuplicateTokenThrows(): void
    {
        $token = RandomHelper::hex(16);
        Hilos::$db->sessions->actions->createAnonymous($token);

        $this->expectException(DuplicateValueException::class);
        $this->expectExceptionMessage('already exists');
        Hilos::$db->sessions->actions->createAnonymous($token);
    }

    /**
     * findByToken resolves a stored session and null for an unknown token.
     *
     * @throws HilosException On database error
     */
    public function testFindByTokenResolvesStoredSession(): void
    {
        $token = RandomHelper::hex(16);
        Hilos::$db->sessions->actions->createAnonymous($token);

        $this->assertSame($token, Hilos::$db->sessions->findByToken($token)?->token);
        $this->assertNull(Hilos::$db->sessions->findByToken(RandomHelper::hex(16)));
    }

    /**
     * bindUser authenticates the session and unbindUser reverts it to anonymous.
     *
     * @throws HilosException On database error
     */
    public function testBindAndUnbindUser(): void
    {
        $userId = (int) Hilos::$db->users->actions->createWithName('User')->id;
        $token = RandomHelper::hex(16);
        Hilos::$db->sessions->actions->createAnonymous($token);

        Hilos::$db->sessions->findByToken($token)?->actions->bindUser($userId);
        $this->assertSame($userId, Hilos::$db->sessions->findByToken($token)?->userId);

        Hilos::$db->sessions->findByToken($token)?->actions->unbindUser();
        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
    }
}
