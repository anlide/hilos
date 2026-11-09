<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Actions\Collection\SessionsActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Integration coverage for the carry-over write action (HIL-479).
 *
 * The point of {@see SessionsActions::carryOver()} next to `createAnonymous()` is that it does
 * not stamp the row with now: a session that survives a restore must come out of it with the
 * lifetime it already had. That, and its two refusals, are what these cases pin - against the
 * real table, because "what ended up in the row" is the whole claim.
 */
final class SessionsActionsCarryOverTest extends HilosSessionIntegrationTestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const int CREATED_AT = 1785575700000;

    private const int EXPIRES_AT = 1788254100000;

    /** Creation moment of the row that came back with the archive, older than the carried one. */
    private const int ARCHIVED_CREATED_AT = 1783408027000;

    /**
     * @throws HilosException When the write fails
     * @throws DatabaseException When reading the row back fails
     */
    public function testCarriedSessionKeepsTheCapturedLifetimeAndIsSeenNow(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (41, 'Person')");
        $session = Hilos::$db->sessions->actions->carryOver(self::TOKEN, 41, self::CREATED_AT, self::EXPIRES_AT);

        $this->assertNotNull($session, 'A token with no row is carried');
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame('41', (string)$row['user_id']);
        $this->assertSame(self::CREATED_AT, $row['created_at'], 'Creation time is carried verbatim');
        $this->assertSame(self::EXPIRES_AT, $row['expires_at'], 'Expiry is carried verbatim, not extended');
        $this->assertNotSame(self::CREATED_AT, $row['last_seen_at'], 'Only last_seen_at is fresh');
        $this->assertNull($row['impersonator_user_id'], 'Impersonation does not survive a restore');
    }

    /**
     * @throws HilosException When the write fails
     * @throws DatabaseException When seeding or reading the row fails
     */
    public function testAnOpenEndedSessionIsCarriedWithoutAnExpiry(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (41, 'Person')");
        Hilos::$db->sessions->actions->carryOver(self::TOKEN, 41, self::CREATED_AT, null);

        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertNull($row['expires_at'], 'A session with no expiry is not given one on the way through');
    }

    /**
     * @throws HilosException When the write fails
     * @throws DatabaseException When seeding or reading the row fails
     */
    public function testATokenThatAlreadyHasARowIsLeftUntouched(): void
    {
        self::seedSession(self::TOKEN, 99, self::ARCHIVED_CREATED_AT, null);

        $session = Hilos::$db->sessions->actions->carryOver(self::TOKEN, 41, self::CREATED_AT, self::EXPIRES_AT);

        $this->assertNull($session, 'A row that came back with the archive is not overwritten');
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame('99', (string)$row['user_id'], 'The archived row keeps its own user');
        $this->assertSame(self::ARCHIVED_CREATED_AT, $row['created_at']);
    }

    /**
     * @throws HilosException When the write fails
     */
    public function testAMalformedTokenIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        Hilos::$db->sessions->actions->carryOver('not-a-token', 41, self::CREATED_AT, self::EXPIRES_AT);
    }
}
