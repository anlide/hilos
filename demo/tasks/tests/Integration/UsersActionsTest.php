<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Integration;

use Demo\Tasks\Hilos;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\HilosException;

/**
 * Integration tests for UsersActions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class UsersActionsTest extends IntegrationTestCase
{
    /**
     * Each registration mints its own user.
     *
     * Nothing identifies an account before its session is bound to it, so two
     * registrations in a row have to be two users - the collision the token
     * argument used to guard against cannot arise, and neither may a silent reuse.
     *
     * @throws HilosException On database error
     */
    public function testRegisterAdminMintsDistinctUsers(): void
    {
        $first = Hilos::$db->users->actions->registerAdmin();
        $second = Hilos::$db->users->actions->registerAdmin();

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

    /**
     * The sign-in mint keeps the name it was given and leaves the admin flag alone.
     *
     * The one door every sign-in road ends at (HIL-623), and the difference from
     * `registerAdmin()` is the whole reason it exists: a person who registers is a member,
     * not an operator, and the name comes from the ceremony rather than from a generator.
     *
     * @throws HilosException On database error
     */
    public function testCreateWithNameKeepsTheNameAndGrantsNothing(): void
    {
        $user = Hilos::$db->users->actions->createWithName('  Ada Lovelace  ');

        $this->assertNotNull($user->id);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertFalse($user->admin);
    }

    /**
     * A blank name is refused rather than stored.
     *
     * A nameless account is a defect wherever it comes from, and this is the one door every
     * road passes through - so the refusal belongs here and not in each caller.
     *
     * @throws HilosException On database error
     */
    public function testCreateWithNameRefusesABlankName(): void
    {
        $this->expectException(EmptyValueException::class);

        Hilos::$db->users->actions->createWithName('   ');
    }
}
