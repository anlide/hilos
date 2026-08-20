<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Integration;

use Demo\SimpleTodo\Hilos;
use Hilos\HilosException;

/**
 * Integration tests for UsersActions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UsersActionsTest extends IntegrationTestCase
{
    /**
     * Registering a guest creates a user with an id and an auto-generated name.
     *
     * @throws HilosException On database error
     */
    public function testRegisterGuestCreatesUser(): void
    {
        $user = Hilos::$db->users->actions->registerGuest();

        $this->assertNotNull($user->id);
        $this->assertStringStartsWith('User', $user->name);
    }

    /**
     * Each guest registration mints its own user.
     *
     * Nothing identifies a guest before its session is bound to it (HIL-407), so
     * two registrations in a row have to be two users - the collision the token
     * argument used to guard against cannot arise, and neither may a silent reuse.
     *
     * @throws HilosException On database error
     */
    public function testRegisterGuestMintsDistinctUsers(): void
    {
        $first = Hilos::$db->users->actions->registerGuest();
        $second = Hilos::$db->users->actions->registerGuest();

        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);
        $this->assertNotSame($first->id, $second->id);
    }

    /**
     * Registering an administrator creates a user that already carries the flag.
     *
     * The whole point of the method: the admin pages open on a row that says admin, and on a
     * fresh installation no row does. A mint that left the flag off would need a grant right
     * behind it - and the id to grant is exactly what nobody can look up yet.
     *
     * @throws HilosException On database error
     */
    public function testRegisterAdminCreatesAdminUser(): void
    {
        $user = Hilos::$db->users->actions->registerAdmin();

        $this->assertNotNull($user->id);
        $this->assertStringStartsWith('Admin', $user->name);
        $this->assertTrue($user->admin);
    }
}
