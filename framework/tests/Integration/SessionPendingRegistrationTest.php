<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Integration coverage for the unfinished registration a session remembers (HIL-612).
 *
 * The memory moved off `hilos_registration_wait` and onto two columns of the session
 * row, and with it came the three writes that had no owner while it was a table of its
 * own: a sweep on the age of the wait, a release naming an ADDRESS rather than a
 * session, and a re-hold that has to move the moment as well as the address. All three
 * are pinned against the real table, because "what ended up in the row" is the whole
 * claim - especially for the sweep, whose criterion IS a column value.
 */
final class SessionPendingRegistrationTest extends HilosSessionIntegrationTestCase
{
    /** Lifetime a wait keeps being served; the same number the verification TTL carries. */
    private const int TTL_SECONDS = 900;

    private const string CREATED_AT = '2026-08-01 09:15:00';

    private const string ABANDONED_TOKEN = 'aa00000000000000000000000000aa01';

    private const string FRESH_TOKEN = 'bb00000000000000000000000000bb02';

    private const string OTHER_TOKEN = 'cc00000000000000000000000000cc03';

    private const string IDENTIFIER = 'waited-on@example.test';

    private const string OTHER_IDENTIFIER = 'somebody-else@example.test';

    /**
     * The sweep clears a wait nobody came back to and leaves a live one standing.
     *
     * The whole criterion is the age of the WAIT, so the two rows differ in nothing but
     * that: both name an address, both belong to a session, and only one of them was
     * written longer ago than a code can live.
     *
     * @throws HilosException When the sweep write fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testTheSweepClearsAnAbandonedWaitAndSparesAFreshOne(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedSession(self::FRESH_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, self::TTL_SECONDS + 60);
        self::seedWait(self::FRESH_TOKEN, self::IDENTIFIER, 0);

        $cleared = Hilos::$db->sessions->actions->sweepStalePendingRegistrations(self::TTL_SECONDS);

        $this->assertSame(1, $cleared, 'Only the wait past the TTL is swept');
        $this->assertNull(self::waitIdentifier(self::ABANDONED_TOKEN), 'The abandoned address is forgotten');
        $this->assertNull(self::waitSince(self::ABANDONED_TOKEN), 'And so is the moment it was written');
        $this->assertSame(
            self::IDENTIFIER,
            self::waitIdentifier(self::FRESH_TOKEN),
            'A wait still inside the TTL is somebody sitting on a live code screen',
        );
    }

    /**
     * The session itself survives the sweep untouched.
     *
     * What ends is the registration, not the browser: a person swept off an abandoned
     * code screen is still signed in, and a row deleted instead of cleared would log
     * them out for having walked away from a form.
     *
     * @throws HilosException When the sweep write fails
     * @throws DatabaseException When seeding or reading the row fails
     */
    public function testTheSweepLeavesTheSessionItself(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, 77, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, self::TTL_SECONDS + 60);

        Hilos::$db->sessions->actions->sweepStalePendingRegistrations(self::TTL_SECONDS);

        $row = self::sessionRow(self::ABANDONED_TOKEN);
        $this->assertNotNull($row, 'The session row outlives the registration it was waiting on');
        $this->assertSame('77', (string)$row['user_id'], 'And keeps the account it was signed into');
    }

    /**
     * Releasing by address clears every session that was waiting on it, and only those.
     *
     * Several sessions on one address is the normal case - a desktop and a phone on the
     * same code screen - and it is why the column carries an index instead of a unique
     * key. A third session waiting on a different address proves the release is aimed.
     *
     * @throws HilosException When the release write fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testReleasingAnAddressClearsEverySessionWaitingOnIt(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedSession(self::FRESH_TOKEN, null, self::CREATED_AT, null);
        self::seedSession(self::OTHER_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);
        self::seedWait(self::FRESH_TOKEN, self::IDENTIFIER, 0);
        self::seedWait(self::OTHER_TOKEN, self::OTHER_IDENTIFIER, 0);

        Hilos::$db->sessions->actions->releasePendingRegistrationFor(self::IDENTIFIER);

        $this->assertNull(self::waitIdentifier(self::ABANDONED_TOKEN), 'The first browser on the address stops waiting');
        $this->assertNull(
            self::waitIdentifier(self::FRESH_TOKEN),
            'And so does the second, which is the point of asking by address',
        );
        $this->assertSame(
            self::OTHER_IDENTIFIER,
            self::waitIdentifier(self::OTHER_TOKEN),
            'A session waiting on another address is somebody else\'s registration',
        );
    }

    /**
     * A second hold on one session re-points the address AND restamps the moment.
     *
     * One session runs one registration at a time, so the newer address replaces the
     * older. The moment has to move with it or the sweep would measure the new wait by
     * the age of the abandoned one and close a code screen the person is looking at.
     *
     * @throws HilosException When the hold write fails
     * @throws DatabaseException When seeding or reading the row fails
     */
    public function testASecondHoldRepointsTheAddressAndMovesTheMoment(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::OTHER_IDENTIFIER, self::TTL_SECONDS - 60);
        $before = self::waitSince(self::ABANDONED_TOKEN);
        $this->assertNotNull($before);

        Hilos::$db->sessions->findByToken(self::ABANDONED_TOKEN)?->actions->holdPendingRegistration(self::IDENTIFIER);

        $this->assertSame(
            self::IDENTIFIER,
            self::waitIdentifier(self::ABANDONED_TOKEN),
            'The session waits on its newest address only',
        );
        $this->assertGreaterThan(
            $before,
            self::waitSince(self::ABANDONED_TOKEN),
            'A resend renews the wait exactly as the first send opened it',
        );
    }

    /**
     * Releasing one session forgets its address and says nothing about the others.
     *
     * The "not that address?" write, and the counterpart of the release by address: it
     * is the end of a flow as ONE browser experienced it, so a second browser on the
     * same address keeps its code screen.
     *
     * @throws HilosException When the release write fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testReleasingOneSessionLeavesTheOthersOnTheSameAddress(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedSession(self::FRESH_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);
        self::seedWait(self::FRESH_TOKEN, self::IDENTIFIER, 0);

        Hilos::$db->sessions->findByToken(self::ABANDONED_TOKEN)?->actions->releasePendingRegistration();

        $this->assertNull(
            self::waitIdentifier(self::ABANDONED_TOKEN),
            'The browser that said "not that address?" stops waiting',
        );
        $this->assertSame(
            self::IDENTIFIER,
            self::waitIdentifier(self::FRESH_TOKEN),
            'The other browser on the address is still owed its code',
        );
    }

    /**
     * Writes a wait onto a seeded session the way {@see SessionActions::holdPendingRegistration()}
     * would have, with the moment placed in the past.
     *
     * Raw SQL rather than the action, because these cases ARRANGE a wait of a given age and
     * the action always stamps now; going through it would leave nothing to sweep.
     *
     * @param string $token Session cookie token to write the wait onto
     * @param string $identifier Normalized identifier the session waits on
     * @param int $ageSeconds How long ago the wait was written, in seconds
     * @throws DatabaseException When the update fails
     */
    private static function seedWait(string $token, string $identifier, int $ageSeconds): void
    {
        Database::sqlRun(
            'UPDATE `hilos_session` '
            . 'SET `pending_registration_identifier` = ?, `pending_registration_since` = ? '
            . 'WHERE `token` = ?',
            [$identifier, date('Y-m-d H:i:s', time() - $ageSeconds), $token],
        );
    }

    /**
     * Reads the address a session is waiting on straight from the database, past every
     * in-memory collection.
     *
     * @param string $token Session cookie token
     * @return ?string Identifier in the row, or null when the session waits on nothing
     * @throws DatabaseException When the query fails
     */
    private static function waitIdentifier(string $token): ?string
    {
        return self::waitColumn($token, 'pending_registration_identifier');
    }

    /**
     * Reads the moment a session's wait was last written, straight from the database.
     *
     * @param string $token Session cookie token
     * @return ?string SQL datetime in the row, or null when the session waits on nothing
     * @throws DatabaseException When the query fails
     */
    private static function waitSince(string $token): ?string
    {
        return self::waitColumn($token, 'pending_registration_since');
    }

    /**
     * Reads one pending-registration column of a session row.
     *
     * @param string $token Session cookie token
     * @param string $column Column to read
     * @return ?string Column value, or null when it is empty or the token holds no row
     * @throws DatabaseException When the query fails
     */
    private static function waitColumn(string $token, string $column): ?string
    {
        Database::sql(
            'SELECT `pending_registration_identifier`, `pending_registration_since` '
            . 'FROM `hilos_session` WHERE `token` = ?',
            [$token],
        );

        $value = Database::row()[$column] ?? null;

        return is_string($value) ? $value : null;
    }
}
