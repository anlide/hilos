<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\HilosException;

/**
 * Integration tests for UsersActions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UsersActionsTest extends IntegrationTestCase
{
    /**
     * createWithName creates a durable user carrying the given display name; the
     * session→user binding is applied separately through the auth seam (HIL-161),
     * so the user row holds no session token (HIL-360 dropped the auto-guest
     * register primitive). Broader session≠user coverage lands in HIL-167 / HIL-370.
     *
     * @throws HilosException On database error
     */
    public function testCreateWithNameCreatesUser(): void
    {
        $user = Hilos::$db->users->actions->createWithName('Alice');

        $this->assertNotNull($user->id);
        $this->assertSame('Alice', $user->name);
    }

    /**
     * An empty name is refused at the one door every registration road passes through (HIL-573).
     *
     * @throws HilosException On database error
     */
    public function testCreateWithNameRefusesAnEmptyName(): void
    {
        $this->expectException(EmptyValueException::class);
        Hilos::$db->users->actions->createWithName('');
    }

    /**
     * A name of nothing but whitespace is the same absence, and is refused the same way.
     *
     * @throws HilosException On database error
     */
    public function testCreateWithNameRefusesABlankName(): void
    {
        $this->expectException(EmptyValueException::class);
        Hilos::$db->users->actions->createWithName("  \t ");
    }
}
