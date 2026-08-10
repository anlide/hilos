<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Actions\Collection\SessionsActions;
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

    private const string CREATED_AT = '2026-08-01 09:15:00';

    private const string EXPIRES_AT = '2026-09-01 09:15:00';

    /**
     * @throws HilosException When the write fails
     * @throws DatabaseException When reading the row back fails
     */
    public function testCarriedSessionKeepsTheCapturedLifetimeAndIsSeenNow(): void
    {
        $session = Hilos::$db->sessions->actions->carryOver(self::TOKEN, 41, self::CREATED_AT, self::EXPIRES_AT);

        $this->assertNotNull($session, 'A token with no row is carried');
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame('41', (string)$row['user_id']);
        $this->assertSame(self::CREATED_AT, (string)$row['created_at'], 'Creation time is carried verbatim');
        $this->assertSame(self::EXPIRES_AT, (string)$row['expires_at'], 'Expiry is carried verbatim, not extended');
        $this->assertNotSame(self::CREATED_AT, (string)$row['last_seen_at'], 'Only last_seen_at is fresh');
        $this->assertNull($row['impersonator_user_id'], 'Impersonation does not survive a restore');
    }

    /**
     * @throws HilosException When the write fails
     * @throws DatabaseException When seeding or reading the row fails
     */
    public function testAnOpenEndedSessionIsCarriedWithoutAnExpiry(): void
    {
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
        self::seedSession(self::TOKEN, 99, '2026-07-07 07:07:07', null);

        $session = Hilos::$db->sessions->actions->carryOver(self::TOKEN, 41, self::CREATED_AT, self::EXPIRES_AT);

        $this->assertNull($session, 'A row that came back with the archive is not overwritten');
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame('99', (string)$row['user_id'], 'The archived row keeps its own user');
        $this->assertSame('2026-07-07 07:07:07', (string)$row['created_at']);
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
