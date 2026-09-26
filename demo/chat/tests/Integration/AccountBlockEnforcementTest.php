<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripOpenedSignalData;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Auth\Session\DTO\DismissAccountBlockedActionDTO;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for block enforcement at the sessions library (HIL-289).
 *
 * Driven in chat because the fact is the project's: a block is read through the users
 * collection chat mounts as its block source (HIL-944), and a framework case has no users
 * collection to ask. What this leaf does not have is a writer of the flag - the admin button,
 * the operator command and the test command are other leaves - so a case writes the column
 * through the object layer and then sends the frame a writer sends.
 *
 * Coverage: the frame signs every session of the person out with the card on it, leaves an
 * administrator's takeover of the person alone and ends the blocked administrator's own
 * takeover; an unblock takes the cards down; a repeated frame writes and sends nothing; the
 * handshake door catches a session the frame could not reach and lowers a stale card; every
 * sign-in path refuses a blocked person ahead of the second factor; the card's Sign out is
 * always answered; a sign-in into another account lowers the card.
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class AccountBlockEnforcementTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string REQUEST_ID = 'req-289';

    private const string SECRET_BYTES = 'hil-289-secret-bytes';

    private ChatAgent $holder;

    /**
     * Boots the agent holding the connections and the library's collections a case writes to.
     *
     * @throws HilosException When runtime setup fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        RtTruthSourceRegistry::register(StateHilosOAuthTrip::RT_COLLECTION, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::secondFactors, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();

        Hilos::initSignalRouter(new ChatSignalRouter());
        Hilos::initBrowser();
        Hilos::$sr->subscribeToPage(PageConstants::MAIN, new WebSocketPageSubscribeSignalDTO(
            'listener-ak',
            PageConstants::MAIN,
            [],
        ));
        $this->holder = new ChatAgent();
    }

    /**
     * Leaves no connection and no provider sign-in behind for the next case.
     *
     * The runtime is shared by every case of the suite, and an ended trip left in it is swept
     * by the next case that ticks the sessions library - a case that holds no claim on the
     * trips. So the trips go here, while this case still holds its claim; an age below zero
     * reaches every ended trip, the one written this millisecond included.
     *
     * @throws HilosException When a runtime clear fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->hilosOAuthTrips->actions->forgetEnded(-1);
        parent::tearDown();
    }

    /**
     * Blocking signs out every session of the person, each with the card naming the confirmed address.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testTheFrameSignsEverySessionOutWithTheCard(): void
    {
        $email = $this->uniqueEmail();
        $userId = $this->registerUser($email);
        $first = $this->signedInSession('block-a1', $userId);
        $second = $this->signedInSession('block-a2', $userId);

        $this->block($userId);
        $this->drainSignals();
        $this->sendBlockChanged($userId);

        foreach ([$first, $second] as $token) {
            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertNull($session?->userId, 'Every session of the person is anonymous');
            $this->assertSame($userId, $session?->blockedUserId, 'and remembers the account it lost');
        }
        $response = $this->lastHandshakeResponseFor('block-a1');
        $this->assertNotNull($response);
        $this->assertNull($response->selfId);
        $this->assertSame(['identifier' => $email], $response->accountBlocked);
    }

    /**
     * An administrator taking the blocked person over keeps the takeover.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAnAdministratorsTakeoverOfTheBlockedPersonIsLeftAlone(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $adminId = $this->registerAdmin();
        $token = $this->signedInSession('takeover-ak', $adminId);
        $this->takeOver($token, $adminId, $userId);

        $this->block($userId);
        $this->sendBlockChanged($userId);

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertSame($userId, $session?->userId);
        $this->assertSame($adminId, $session?->impersonatorUserId);
        $this->assertNull($session?->blockedUserId);
    }

    /**
     * A blocked administrator loses the takeover of somebody else as well as their own sessions.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testABlockedAdministratorLosesTheTakeoverOfSomebodyElse(): void
    {
        $targetId = $this->registerUser($this->uniqueEmail());
        $adminId = $this->registerAdmin();
        $token = $this->signedInSession('admin-ak', $adminId);
        $this->takeOver($token, $adminId, $targetId);

        $this->block($adminId);
        $this->sendBlockChanged($adminId);

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNull($session?->userId);
        $this->assertNull($session?->impersonatorUserId);
        $this->assertSame($adminId, $session?->blockedUserId);
    }

    /**
     * An unblock takes the card down, and a second frame of either kind finds nothing to do.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAnUnblockTakesTheCardDownAndRepeatsAreSilent(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $token = $this->signedInSession('unblock-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);

        $this->drainSignals();
        $this->sendBlockChanged($userId);
        $this->assertSame([], $this->stateFrames(), 'A repeated block frame sends nothing');

        $this->unblock($userId);
        $this->sendBlockChanged($userId);

        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->blockedUserId);
        $response = $this->lastHandshakeResponseFor('unblock-ak');
        $this->assertNotNull($response);
        $this->assertNull($response->accountBlocked);

        $this->sendBlockChanged($userId);
        $this->assertSame([], $this->stateFrames(), 'A repeated unblock frame sends nothing');
    }

    /**
     * A tab that comes back to a session of a blocked person is signed out at the door, with the card.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testTheHandshakeDoorCatchesASessionTheFrameNeverReached(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $token = $this->signedInSession('door-ak', $userId);
        $this->block($userId);
        $this->drainSignals();

        $this->deliverHandshake($this->holder, $this->handshake('door-ak-2', $token));

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNull($session?->userId);
        $this->assertSame($userId, $session?->blockedUserId);
        $this->assertNotNull($this->lastHandshakeResponseFor('door-ak-2')?->accountBlocked);
    }

    /**
     * A card whose account was unblocked while the browser was away is lowered at the door.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testTheHandshakeDoorLowersAStaleCard(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $token = $this->signedInSession('stale-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $this->unblock($userId);
        $this->drainSignals();

        $this->deliverHandshake($this->holder, $this->handshake('stale-ak-2', $token));

        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->blockedUserId);
        $this->assertNull($this->lastHandshakeResponseFor('stale-ak-2')?->accountBlocked);
    }

    /**
     * A proven sign-in into a blocked account is refused ahead of the second factor, with the card.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testASignInIsRefusedAheadOfTheSecondFactor(): void
    {
        $email = $this->uniqueEmail();
        $userId = $this->registerUser($email);
        Hilos::$db->secondFactors->actions
            ->startEnrolment($userId, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
        $this->block($userId);
        $token = $this->anonymousSession('grant-ak');
        $this->drainSignals();

        $outcome = $this->grant($token, $userId, 'grant-ak');

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNotNull($session, 'Nothing rotated: nobody was signed in');
        $this->assertNull($session->userId);
        $this->assertNull($session->pendingSecondFactorUserId, 'The second factor does not hold a refused sign-in');
        $this->assertSame($userId, $session->blockedUserId);
        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->ok);
        $this->assertSame(AuthFlowOutcome::CODE_ACCOUNT_BLOCKED, $outcome->code);
        $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
        $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
        $this->assertSame(['identifier' => $email], $this->lastHandshakeResponseFor('grant-ak')?->accountBlocked);
    }

    /**
     * A provider sign-in into a blocked account ends the trip with its own reason.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAProviderSignInEndsTheTripAsBlocked(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->block($userId);
        $token = $this->anonymousSession('trip-ak');
        $tripKeyHash = StateHilosOAuthTrip::hashKey(RandomHelper::hex(16));
        $this->toLibrary(HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED, new OAuthTripOpenedSignalData(
            tripKeyHash: $tripKeyHash,
            sessionTokenHash: StateProtectedModeRuntime::hashSessionToken($token),
            acceptKey: 'trip-ak',
            mode: OAuthStateSigner::MODE_LOGIN,
            provider: 'google',
        ));
        $this->drainSignals();

        $this->toLibrary(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, new AuthSessionGrantSignalData(
            sessionToken: $token,
            userId: $userId,
            acceptKey: 'trip-ak',
            tripKeyHash: $tripKeyHash,
        ));

        $this->assertSame(OAuthResultSignalData::REASON_ACCOUNT_BLOCKED, Hilos::$rt->hilosOAuthTrips[$tripKeyHash]?->ending);
        $this->assertSame(OAuthResultSignalData::REASON_ACCOUNT_BLOCKED, $this->lastOAuthResultReason('trip-ak'));
        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNull($session?->userId);
        $this->assertSame($userId, $session?->blockedUserId);
    }

    /**
     * A new password saved through recovery stays, but the browser gets the card and no session.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testARecoveredPasswordDoesNotSignABlockedPersonIn(): void
    {
        $email = $this->uniqueEmail();
        $userId = $this->registerUser($email);
        $this->block($userId);
        $token = $this->anonymousSession('recovery-ak');
        $this->drainSignals();

        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(
            new AgentSignalData(new AuthPasswordChangedSignalData(
                userId: $userId,
                sessionToken: $token,
                acceptKey: 'recovery-ak',
                identifier: $email,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            )),
            '',
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED,
        ));
        $outcome = $this->deliverLibraryFrames($this->holder);

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNull($session?->userId);
        $this->assertNull($session?->pendingAck, 'No "password changed" panel over the card');
        $this->assertSame($userId, $session?->blockedUserId);
        $this->assertSame(AuthFlowOutcome::CODE_ACCOUNT_BLOCKED, $outcome?->code);
    }

    /**
     * Sign out on the card lowers it in every tab and is answered, and a second press is answered too.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testSignOutOnTheCardIsAlwaysAnswered(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $token = $this->signedInSession('dismiss-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $this->drainSignals();

        $answer = $this->dismiss('dismiss-ak');
        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->blockedUserId);
        $this->assertNotNull($answer, 'The press is answered on the tab that pressed');
        $this->assertNull($answer->accountBlocked);

        $again = $this->dismiss('dismiss-ak');
        $this->assertNotNull($again, 'A press with no card left is answered as well');
        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->blockedUserId);
    }

    /**
     * A sign-in into another account from a browser holding the card takes the card down.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testASignInIntoAnotherAccountLowersTheCard(): void
    {
        $blockedId = $this->registerUser($this->uniqueEmail());
        $otherId = $this->registerUser($this->uniqueEmail());
        $token = $this->signedInSession('switch-ak', $blockedId);
        $this->block($blockedId);
        $this->sendBlockChanged($blockedId);
        $this->drainSignals();

        $this->grant($token, $otherId, 'switch-ak');

        $session = $this->sessionOf('switch-ak');
        $this->assertSame($otherId, $session?->userId);
        $this->assertNull($session?->blockedUserId);
        $this->assertNull($this->lastHandshakeResponseFor('switch-ak')?->accountBlocked);
    }

    /**
     * Registers a person with a confirmed address the card can name.
     *
     * @param string $email Confirmed address of the person
     * @return int New user id
     * @throws HilosException When the user or identity write fails
     */
    private function registerUser(string $email): int
    {
        $userId = (int) Hilos::$db->users->actions->createWithName('Blocked Candidate')->id;
        Hilos::$db->identities->createPasswordIdentity($userId, $email, 'a long enough passphrase')->markVerified();

        return $userId;
    }

    /**
     * Registers an administrator with no address.
     *
     * @return int New admin user id
     * @throws HilosException When the user write fails
     */
    private function registerAdmin(): int
    {
        $userId = (int) Hilos::$db->users->actions->createWithName('Admin')->id;
        Hilos::$db->users[$userId]->actions->setAdmin(true);

        return $userId;
    }

    /**
     * Writes the block flag the way a writer of it would, leaving the frame to the case.
     *
     * @param int $userId Person to block
     * @throws HilosException When the user write fails
     */
    private function block(int $userId): void
    {
        $user = Hilos::$db->users[$userId]->actions->object;
        $user->block = true;
        $user->sync();
    }

    /**
     * Clears the block flag the way a writer of it would, leaving the frame to the case.
     *
     * @param int $userId Person to unblock
     * @throws HilosException When the user write fails
     */
    private function unblock(int $userId): void
    {
        $user = Hilos::$db->users[$userId]->actions->object;
        $user->block = false;
        $user->sync();
    }

    /**
     * Sends the library the frame a block writer sends, and delivers what it answers with.
     *
     * @param int $userId Person whose flag was written
     * @throws HilosException When the frame or what follows it fails
     */
    private function sendBlockChanged(int $userId): void
    {
        $this->toLibrary(HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED, new AccountBlockChangedSignalData($userId));
    }

    /**
     * Hands the library one frame addressed to it and runs what it queued through the holder.
     *
     * @param string $name Frame name
     * @param AccountBlockChangedSignalData|AuthSessionGrantSignalData|OAuthTripOpenedSignalData $frame Payload
     * @return ?AuthFlowOutcome Outcome the last state frame handed over, or null when none carried one
     * @throws HilosException When the frame or what follows it fails
     */
    private function toLibrary(
        string $name,
        AccountBlockChangedSignalData|AuthSessionGrantSignalData|OAuthTripOpenedSignalData $frame,
    ): ?AuthFlowOutcome {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(new AgentSignalData($frame), '', $name));

        return $this->deliverLibraryFrames($this->holder);
    }

    /**
     * Hands the library a password sign-in of the person, answered on the submitting tab.
     *
     * @param string $token Session the proof arrived on
     * @param int $userId Person the proof resolved to
     * @param string $acceptKey Connection that submitted
     * @return ?AuthFlowOutcome Outcome the submit was answered with
     * @throws HilosException When the grant or what follows it fails
     */
    private function grant(string $token, int $userId, string $acceptKey): ?AuthFlowOutcome
    {
        return $this->toLibrary(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, new AuthSessionGrantSignalData(
            sessionToken: $token,
            userId: $userId,
            acceptKey: $acceptKey,
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::HILOS_LOGIN,
        ));
    }

    /**
     * Presses Sign out on the card as a tracked action and returns the answer the pressing tab got.
     *
     * @param string $acceptKey Connection that pressed
     * @return ?SessionStateSignalData State frame answering the press, or null when none did
     * @throws HilosException When the action fails
     */
    private function dismiss(string $acceptKey): ?SessionStateSignalData
    {
        $library = $this->sessionsLibrary();
        $library->beginActionDispatch(self::REQUEST_ID);
        try {
            $library->onAgentAction(
                $acceptKey,
                HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED,
                new DismissAccountBlockedActionDTO(),
            );
            $this->assertTrue($library->actionReplyDeferred(), 'The answer rides the state frame');
        } finally {
            $library->endActionDispatch();
        }

        foreach ($this->stateFrames() as $frame) {
            if ($frame->requestId === self::REQUEST_ID && $frame->acceptKeys === [$acceptKey]) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * Opens a session for a fresh cookie on one connection and signs a person into it.
     *
     * @param string $acceptKey Connection of the session
     * @param int $userId Person to sign in
     * @return string Token the session answers to
     * @throws HilosException When the handshake or the sign-in fails
     */
    private function signedInSession(string $acceptKey, int $userId): string
    {
        $token = $this->anonymousSession($acceptKey);
        $this->authenticateSession($this->holder, $token, $userId, null);

        return $token;
    }

    /**
     * Opens an anonymous session for a fresh cookie on one connection.
     *
     * @param string $acceptKey Connection of the session
     * @return string Token the session answers to
     * @throws HilosException When the handshake fails
     */
    private function anonymousSession(string $acceptKey): string
    {
        $token = RandomHelper::hex(16);
        $this->deliverHandshake($this->holder, $this->handshake($acceptKey, $token));

        return $token;
    }

    /**
     * Puts an administrator's session behind a takeover of another person.
     *
     * @param string $token Administrator's session token
     * @param int $adminId Administrator taking over
     * @param int $targetId Person taken over
     * @throws HilosException When the rebind fails
     */
    private function takeOver(string $token, int $adminId, int $targetId): void
    {
        $this->rebindSession($this->holder, new SessionRebindSignalData(
            sessionToken: $token,
            userId: $targetId,
            impersonatorUserId: $adminId,
        ));
    }

    /**
     * Drains the queue and returns every session state frame it held.
     *
     * @return list<SessionStateSignalData> State frames queued since the last drain
     */
    private function stateFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof SessionStateSignalData) {
                $frames[] = $payload->data;
            }
        }

        return $frames;
    }

    /**
     * Drains the queue and returns the reason of the last provider result sent to one connection.
     *
     * @param string $acceptKey Connection the result is addressed to
     * @return ?string Reason of the last result, or null when none was sent
     */
    private function lastOAuthResultReason(string $acceptKey): ?string
    {
        $reason = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData
                && $data->targetAcceptKey === $acceptKey
                && $data->data instanceof OAuthResultSignalData) {
                $reason = $data->data->reason;
            }
        }

        return $reason;
    }

    /**
     * @return string Unique lowercase address for one person
     */
    private function uniqueEmail(): string
    {
        return 'blocked-' . RandomHelper::hex(6) . '@example.test';
    }

    /**
     * Builds a handshake signal for an accept key and cookie token.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $token Session cookie token
     * @return WebSocketHandshakeSignalDTO Handshake payload
     */
    private function handshake(string $acceptKey, string $token): WebSocketHandshakeSignalDTO
    {
        return new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $token,
        );
    }
}
