<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Integration;

use Demo\Tasks\Hilos;
use Hilos\HilosException;

/**
 * Integration tests for GuestsActions.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class GuestsActionsTest extends IntegrationTestCase
{
    /** Session token of the browser these tests visit as. */
    private const string SESSION_TOKEN = 'cccccccccccccccccccccccccccccccc';

    /** Session token of a second, unrelated browser. */
    private const string OTHER_SESSION_TOKEN = 'dddddddddddddddddddddddddddddddd';

    /**
     * A first sight of a session mints a guest row with a generated name.
     *
     * @throws HilosException On database error
     */
    public function testEnsureForSessionMintsNamedGuest(): void
    {
        $guest = Hilos::$db->guests->actions->ensureForSession(self::SESSION_TOKEN);

        $this->assertNotNull($guest->id);
        $this->assertSame(self::SESSION_TOKEN, $guest->sessionToken);
        $this->assertMatchesRegularExpression('/^Guest\d{4}$/', $guest->name);
    }

    /**
     * The same session keeps the same guest row, and no second one appears.
     *
     * This is what makes a visitor's name survive a reconnect and agree across
     * tabs: the row is found by the token rather than minted again.
     *
     * @throws HilosException On database error
     */
    public function testEnsureForSessionReusesTheRowOfThatSession(): void
    {
        $first = Hilos::$db->guests->actions->ensureForSession(self::SESSION_TOKEN);
        $second = Hilos::$db->guests->actions->ensureForSession(self::SESSION_TOKEN);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->name, $second->name);
    }

    /**
     * Two browsers are two guests.
     *
     * @throws HilosException On database error
     */
    public function testEnsureForSessionMintsOneGuestPerSession(): void
    {
        $mine = Hilos::$db->guests->actions->ensureForSession(self::SESSION_TOKEN);
        $theirs = Hilos::$db->guests->actions->ensureForSession(self::OTHER_SESSION_TOKEN);

        $this->assertNotSame($mine->id, $theirs->id);
    }

    /**
     * Deleting the guest of a session removes it, and asking twice is not an error.
     *
     * The second call is the normal case, not an edge one: the handshake of an
     * account clears the guest row on every frame, and only the first of them has
     * anything to clear.
     *
     * @throws HilosException On database error
     */
    public function testDeleteForSessionRemovesTheRowAndToleratesAbsence(): void
    {
        $before = Hilos::$db->guests->actions->ensureForSession(self::SESSION_TOKEN);

        Hilos::$db->guests->actions->deleteForSession(self::SESSION_TOKEN);
        Hilos::$db->guests->actions->deleteForSession(self::SESSION_TOKEN);

        // A fresh id is the proof the row went: had it survived, this call would
        // have found it and handed back the one above.
        $after = Hilos::$db->guests->actions->ensureForSession(self::SESSION_TOKEN);

        $this->assertNotSame($before->id, $after->id);
    }
}
