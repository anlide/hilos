<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitHeldSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationWaitMovedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Identity\IdentityType;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for the unfinished registration a session remembers (HIL-612).
 *
 * The memory moved off `hilos_registration_wait` and onto two columns of the session
 * row, and with it came the three writes that had no owner while it was a table of its
 * own: a sweep on the age of the wait, a release naming an ADDRESS rather than a
 * session, and a re-hold that has to move the moment as well as the address. All three
 * are pinned against the real table, because "what ended up in the row" is the whole
 * claim - especially for the sweep, whose criterion IS a column value.
 *
 * HIL-833 gave the wait a second job and the cases below it: a browser that LOST the race
 * for its address keeps the wait, and the wait is then the only copy of that news. So the
 * handshake is driven here too, past no fake, and what is read is the step frame the
 * library publishes - which is the sentence the browser will be rebuilt from.
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

    /** Account the winner of the race ended up with; no user table is read, only the identity row. */
    private const int WINNER_USER_ID = 41;

    /** Accept key of the connection whose handshake the cases below drive. */
    private const string ACCEPT_KEY = 'accept-returning-tab';

    /** Seconds a seeded hold has left - long enough that no case can outlive it. */
    private const int HOLD_TTL_SECONDS = 600;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

    /** @var ?RtContext Runtime context to restore after the test */
    private ?RtContext $previousRt = null;

    /**
     * Raises the two things a handshake needs beside the tables: somewhere to queue its frame,
     * and the registration waits it parks a returning socket on.
     *
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        $rt = new SessionPendingRegistrationTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        RtTruthSourceRegistry::registerDaemon(StateRegistrationWaiter::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateRegistrationWaiter::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

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
     * The write a canceled registration makes, and the counterpart of the release by
     * address: it is the end of a flow as ONE browser experienced it, so a second browser
     * on the same address keeps its code screen.
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
            'The browser that canceled its registration stops waiting',
        );
        $this->assertSame(
            self::IDENTIFIER,
            self::waitIdentifier(self::FRESH_TOKEN),
            'The other browser on the address is still owed its code',
        );
    }

    /**
     * A wait with no hold behind it, on an address that became somebody's, IS the news.
     *
     * The whole of HIL-833 seen from the returning browser. It was offline in the second
     * the race was settled, so the live converge reached nobody here; what is left is the
     * address on its row and the absence of a hold under it, and those two facts together
     * say it lost. The step it is handed is where that person belongs - the address field,
     * under the sign-in intent - and the reason rides with it so the surface can say which
     * of the several ways off a code screen this was.
     *
     * @throws HilosException When the handshake or a seed fails
     * @throws DatabaseException When seeding the rows fails
     */
    public function testAHandshakeTellsABrowserThatLostTheAddressWhileItWasAway(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);
        self::seedIdentity(self::WINNER_USER_ID, IdentityType::PASSWORD, self::IDENTIFIER);

        $step = $this->handshakeStep(self::ABANDONED_TOKEN);

        $this->assertNotNull($step, 'Silence here is what used to leave the person on a dead code screen');
        $this->assertSame(self::IDENTIFIER, $step[HandshakeResponseSignalData::identifier]);
        $this->assertSame(AuthFlowStep::IDENTIFIER, $step[HandshakeResponseSignalData::step]);
        $this->assertSame(AuthFlowIntent::LOGIN, $step[HandshakeResponseSignalData::intent]);
        $this->assertSame(
            AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
            $step[HandshakeResponseSignalData::code],
            'The reason is what tells this apart from a session that was never in a flow',
        );
        $this->assertNull(
            $step[HandshakeResponseSignalData::expiresAt],
            'An address field counts down to nothing, so no moment is promised',
        );
    }

    /**
     * A wait with no hold on an address nobody took still says nothing.
     *
     * The other half of the same branch, and the reason it asks the identities rather than
     * assuming: a hold swept by its own TTL leaves exactly the same pair of facts minus the
     * account, and that person lost no race - the address is theirs to register again.
     *
     * @throws HilosException When the handshake fails
     * @throws DatabaseException When seeding the rows fails
     */
    public function testAWaitWhoseHoldMerelyRanOutIsStillNoStepAtAll(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);

        $this->assertNull(
            $this->handshakeStep(self::ABANDONED_TOKEN),
            'Nobody owns the address, so there is no loss to report',
        );
    }

    /**
     * A live hold still names the code screen, and names no reason.
     *
     * The path every ordinary registration takes, pinned beside the new one because the new
     * branch is reached by the ABSENCE of a hold: a mistake there would answer "your address
     * was taken" to everybody sitting on a perfectly live code.
     *
     * @throws HilosException When the handshake or the hold write fails
     * @throws DatabaseException When seeding the rows fails
     */
    public function testALiveHoldStillNamesTheCodeScreenAndNoReason(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);
        self::seedHold(self::ABANDONED_TOKEN, self::IDENTIFIER);

        $step = $this->handshakeStep(self::ABANDONED_TOKEN);

        $this->assertNotNull($step);
        $this->assertSame(AuthFlowStep::CODE, $step[HandshakeResponseSignalData::step]);
        $this->assertSame(AuthFlowIntent::REGISTER, $step[HandshakeResponseSignalData::intent]);
        $this->assertNull($step[HandshakeResponseSignalData::code], 'Standing where you left off is not a rollback');
        $this->assertIsInt(
            $step[HandshakeResponseSignalData::expiresAt],
            'A code screen counts down, so the moment travels',
        );
    }

    /**
     * The browser that lost is not parked on the address it lost.
     *
     * The other half of keeping the wait (HIL-833). A wait used to mean "still racing", and
     * parking was decided by it; now a loser carries one for as long as the sweep lets it,
     * and parking it on that name would join it to the NEXT stranger's registration of the
     * same address and roll its surface back on somebody else's news. What says a browser is
     * still in the race is a hold of its own, so that is what the park asks.
     *
     * @throws HilosException When the handshake or the hold write fails
     * @throws DatabaseException When seeding the rows fails
     */
    public function testTheBrowserThatLostIsNotParkedOnTheAddressItLost(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedSession(self::FRESH_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);
        self::seedWait(self::FRESH_TOKEN, self::IDENTIFIER, 0);
        self::seedHold(self::FRESH_TOKEN, self::IDENTIFIER);
        self::seedIdentity(self::WINNER_USER_ID, IdentityType::PASSWORD, self::IDENTIFIER);

        $this->handshakeStep(self::ABANDONED_TOKEN);
        $this->handshakeStep(self::FRESH_TOKEN, 'accept-still-racing');

        $parked = [];
        foreach (Hilos::$rt?->hilosRegistrationWaiters->forIdentifier(self::IDENTIFIER) ?? [] as $waiter) {
            $parked[] = $waiter->sessionToken;
        }

        $this->assertSame(
            [self::FRESH_TOKEN],
            $parked,
            'Only the browser still holding the address belongs to its converge',
        );
    }

    /**
     * A park frame from the users library is the whole park since HIL-1044: the holder brings the
     * waiter row into being and writes the wait on the session row, both of which the library
     * used to write itself.
     *
     * @throws HilosException When the park fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testAParkFrameParksTheTabAndWritesTheSessionsWait(): void
    {
        self::seedSession(self::FRESH_TOKEN, null, self::CREATED_AT, null);

        new SessionPendingRegistrationTestAgent()->onSignalAgent(
            new AgentSignalData(data: new AuthRegistrationWaitMovedSignalData(
                self::ACCEPT_KEY,
                self::IDENTIFIER,
                self::FRESH_TOKEN,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED,
        );

        $this->assertSame(self::IDENTIFIER, Hilos::$rt?->hilosRegistrationWaiters[self::ACCEPT_KEY]?->identifier);
        $this->assertSame(self::IDENTIFIER, self::waitIdentifier(self::FRESH_TOKEN));
    }

    /**
     * The code agent's word that a code went out to a free number leaves the session waiting on
     * it - written by this library, which owns the row, and no longer by the agent (HIL-1044).
     *
     * @throws HilosException When the write fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testTheCodeAgentsWordLeavesTheSessionWaitingOnTheNumber(): void
    {
        self::seedSession(self::FRESH_TOKEN, null, self::CREATED_AT, null);

        new SessionPendingRegistrationTestAgent()->onSignalAgent(
            new AgentSignalData(data: new AuthRegistrationWaitHeldSignalData(self::FRESH_TOKEN, self::IDENTIFIER)),
            'test',
            HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD,
        );

        $this->assertSame(self::IDENTIFIER, self::waitIdentifier(self::FRESH_TOKEN));
    }

    /**
     * The browser that WON walks away remembering no registration.
     *
     * The guard on the line this leaf removed. Until HIL-833 the converge cleared the wait of
     * every session on the address at once, the winner's among them; now only the losers are
     * meant to keep theirs, and what clears the winner's is its own sign-in. Were that line
     * ever to go, the winner would come back to a handshake reading a wait with no hold on an
     * address that has an account - its OWN - and be told, while signed in, that the address
     * it just registered was taken.
     *
     * @throws HilosException When the grant or a seed fails
     * @throws DatabaseException When seeding the rows fails
     */
    public function testTheBrowserThatWonRemembersNoRegistrationAfterItSignsIn(): void
    {
        self::seedSession(self::ABANDONED_TOKEN, null, self::CREATED_AT, null);
        self::seedWait(self::ABANDONED_TOKEN, self::IDENTIFIER, 0);
        self::seedHold(self::ABANDONED_TOKEN, self::IDENTIFIER);

        new SessionPendingRegistrationTestAgent()->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::ABANDONED_TOKEN,
                userId: self::WINNER_USER_ID,
                acceptKey: self::ACCEPT_KEY,
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );

        $this->assertNull(
            self::waitIdentifier(self::ABANDONED_TOKEN),
            'A session that belongs to somebody stands on no registration',
        );
    }

    /**
     * Drives one handshake and reads the step off the frame the library published.
     *
     * The frame is what the project hands the browser, so reading it is reading what the
     * person will be shown; the queue is drained first so the step belongs to this call.
     *
     * @param string $token Session cookie token the socket resolved to
     * @param string $acceptKey Accept key of the handshaking connection
     * @return ?array<string, mixed> Auth step the response carries, or null when it carries none
     * @throws HilosException When the handshake fails
     */
    private function handshakeStep(string $token, string $acceptKey = self::ACCEPT_KEY): ?array
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
        }

        new SessionPendingRegistrationTestAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: null,
                sessionToken: $token,
            ),
            'test',
            'handshake',
        );

        $step = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof AgentSignalData || !$signal->data->data instanceof SessionStateSignalData) {
                continue;
            }
            $step = $signal->data->data->pendingAuthStep;
        }

        return $step;
    }

    /**
     * Writes a live hold of one session on an address, the way a registration submit would.
     *
     * Raw SQL rather than the service, for the reason the wait beside it is seeded that way:
     * the service reads its lifetime off the environment, and these cases want a hold that
     * certainly outlives the run rather than whatever a deployment configured.
     *
     * @param string $token Session cookie token of the browser leading the registration
     * @param string $identifier Normalized identifier being held
     * @throws DatabaseException When the insert fails
     */
    private static function seedHold(string $token, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_registration_reservation` '
            . '(`type`, `identifier`, `session_token`, `expires_at`) VALUES (?, ?, ?, ?)',
            [
                IdentityType::PASSWORD,
                $identifier,
                $token,
                date('Y-m-d H:i:s', time() + self::HOLD_TTL_SECONDS),
            ],
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

/**
 * A sessions library with nothing of a project on it: the handshake path under test is
 * framework-owned end to end.
 */
final class SessionPendingRegistrationTestAgent extends AbstractSessionsLibraryAgent
{
}

/**
 * A runtime context carrying the framework's own collections and the sign-in feature's, which
 * is where a handshake parks the socket it just answered.
 */
final class SessionPendingRegistrationTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
