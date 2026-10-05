<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\DataExport\DataExportState;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DTO\DataExportForgetUserSignalData;
use Hilos\DataExport\DTO\DataExportOrderActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpConfirmActionDTO;
use Hilos\Auth\StepUp\DTO\StepUpOpeningReplyDTO;
use Hilos\Auth\StepUp\DTO\StepUpStartActionDTO;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\StepUp\StepUpMethod;
use Demo\Chat\Agents\Hilos\DataExportAgent;
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
use Hilos\Auth\SecondFactor\SecondFactorPendingMode;
use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Auth\Session\DTO\DismissAccountBlockedActionDTO;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\HilosSessionRotation;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;
use Random\RandomException;

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
 * takeover; a repeated frame writes and sends nothing; the handshake door catches a session the
 * frame could not reach; every sign-in path refuses a blocked person ahead of the second factor;
 * the card's Sign out is always answered; a sign-in into another account lowers the card.
 *
 * The unblock gives the browser back (HIL-1188): a live tab that was inside comes back signed in
 * on a new token, and so does one refused at sign-in that no second factor holds; one the factor
 * holds waits on the code step; a refused sign-in over a "was inside" card does not lower it; a
 * browser with no live tab is signed back in at its next handshake, or sent to the code step
 * there; a blocked administrator comes back as themselves; Sign out on the card, a sign-in into
 * another account and an expired row return nobody; a recovered password cancels the return of
 * every other browser; the unblock writes one `account_block_lifted` line.
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
            $this->assertNull(Hilos::$db->sessions->findByToken($token), 'The old cookie no longer names a session');
        }
        foreach (['block-a1', 'block-a2'] as $acceptKey) {
            $session = $this->sessionOf($acceptKey);
            $this->assertNotNull($session);
            $this->assertNull($session->userId, 'Every session of the person is anonymous');
            $this->assertSame($userId, $session->blockedUserId, 'and remembers the account it lost');
        }
        $response = $this->lastHandshakeResponseFor('block-a1');
        $this->assertNotNull($response);
        $this->assertNull($response->selfId);
        $this->assertSame(['identifier' => $email, 'dataExport' => null], $response->accountBlocked);
    }

    /**
     * A block removes a signed-out browser's trust even when the person has no active session.
     *
     * @throws HilosException When a user, session, or trust write fails
     * @throws RandomException When a fixture token cannot be minted
     */
    public function testBlockWithoutActiveSessionsRevokesOldTrust(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $library = $this->sessionsLibrary();
        $sessionId = $this->underAgent($library, function () use ($userId): int {
            $session = Hilos::$db->sessions->actions->createAnonymous(RandomHelper::secureHex(16));
            $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
            Hilos::$db->secondFactorTrusts->actions->trust($session->id, $userId, $until);
            Hilos::$db->secondFactorTrusts->actions->trust(0, $userId, $until);

            return $session->id;
        });

        $this->block($userId);
        $this->sendBlockChanged($userId);
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, 30));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted(0, $userId, 30));

        $this->unblock($userId);
        $this->sendBlockChanged($userId);
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($sessionId, $userId, 30));
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

        $session = $this->sessionOf('admin-ak');
        $this->assertNotNull($session);
        $this->assertNotSame($token, $session->token);
        $this->assertNull($session?->userId);
        $this->assertNull($session?->impersonatorUserId);
        $this->assertSame($adminId, $session?->blockedUserId);
    }

    /**
     * A blocked administrator pulled out of somebody else's account comes back as themselves.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testABlockedAdministratorComesBackAsThemselves(): void
    {
        $targetId = $this->registerUser($this->uniqueEmail());
        $adminId = $this->registerAdmin();
        $token = $this->signedInSession('back-admin-ak', $adminId);
        $this->takeOver($token, $adminId, $targetId);
        $this->block($adminId);
        $this->sendBlockChanged($adminId);

        $this->unblock($adminId);
        $this->sendBlockChanged($adminId);

        $session = $this->sessionOf('back-admin-ak');
        $this->assertSame($adminId, $session?->userId);
        $this->assertNull($session?->impersonatorUserId, 'The takeover does not come back on its own');
        $this->assertNull($session?->blockedUserId);
    }

    /**
     * A card on a row that outlived its own expiry is only lowered: the return ends with the session.
     *
     * @throws HilosException When setup or a frame fails
     * @throws JsonException When the log line cannot be decoded
     */
    public function testAnExpiredCardIsOnlyLowered(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('expired-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $card = $this->sessionOf('expired-ak');
        $this->assertNotNull($card);
        $cardId = $card->id;
        $cardToken = $card->token;
        $row = $card->actions->object;
        $row->expiresAt = date('Y-m-d H:i:s', time() - 1);
        $row->sync();

        $this->unblock($userId);
        $line = $this->liftedLogLine(fn () => $this->sendBlockChanged($userId));

        $session = $this->sessionOf('expired-ak');
        $this->assertSame($cardToken, $session?->token, 'Nothing rotated');
        $this->assertNull($session?->userId);
        $this->assertNull($session?->blockedUserId);
        $this->assertSame([$cardId], $line['lowered'] ?? null);
    }

    /**
     * An unblock signs a live tab back in on a new token, and a second frame of either kind finds nothing to do.
     *
     * @throws HilosException When setup or a frame fails
     * @throws JsonException When the log line cannot be decoded
     */
    public function testAnUnblockSignsALiveTabBackInAndRepeatsAreSilent(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('unblock-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $card = $this->sessionOf('unblock-ak');
        $this->assertNotNull($card);
        $this->assertTrue($card->blockedSignedIn, 'The block threw a signed-in browser out');
        $cardId = $card->id;
        $cardToken = $card->token;

        $this->drainSignals();
        $this->sendBlockChanged($userId);
        $this->assertSame([], $this->stateFrames(), 'A repeated block frame sends nothing');

        $this->unblock($userId);
        $line = $this->liftedLogLine(fn () => $this->sendBlockChanged($userId));

        $session = $this->sessionOf('unblock-ak');
        $this->assertNotNull($session);
        $this->assertSame($cardId, $session->id, 'The same row comes back');
        $this->assertNotSame($cardToken, $session->token, 'on a new token');
        $this->assertSame($userId, $session->userId);
        $this->assertNull($session->blockedUserId);
        $this->assertFalse($session->blockedSignedIn);
        $this->assertNotNull($this->rotationOnto($session->token), 'The tab is handed the ticket for the new cookie');
        $response = $this->lastHandshakeResponseFor('unblock-ak');
        $this->assertNotNull($response);
        $this->assertSame($userId, $response->selfId);
        $this->assertNull($response->accountBlocked);
        self::assertSame(DataExportGroup::forUser($userId), Hilos::$sr->groupSubscriptionName('unblock-ak', DataExportGroup::NAME));
        $this->assertSame([
            'event' => 'account_block_lifted',
            'user' => $userId,
            'returned' => [$cardId],
            'secondFactor' => [],
            'lowered' => [],
        ], $line);

        $this->sendBlockChanged($userId);
        $this->assertSame([], $this->stateFrames(), 'A repeated unblock frame sends nothing');
    }

    /**
     * A browser refused at sign-in comes back signed in when no second factor holds it.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAnUnblockSignsARefusedBrowserInWhenNoSecondFactorHoldsIt(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->block($userId);
        $token = $this->anonymousSession('refused-ak');
        $this->grant($token, $userId, 'refused-ak');
        $this->assertFalse(Hilos::$db->sessions->findByToken($token)?->blockedSignedIn, 'A refused sign-in is not "was inside"');

        $this->unblock($userId);
        $this->sendBlockChanged($userId);

        $session = $this->sessionOf('refused-ak');
        $this->assertSame($userId, $session?->userId);
        $this->assertNotSame($token, $session?->token);
        $this->assertNull($session?->blockedUserId);
    }

    /**
     * A browser refused at sign-in waits on the code step when the person's second factor holds it.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAnUnblockSendsARefusedBrowserToItsSecondFactor(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->enrolSecondFactor($userId);
        $this->block($userId);
        $token = $this->anonymousSession('held-ak');
        $this->grant($token, $userId, 'held-ak');
        $this->drainSignals();

        $this->unblock($userId);
        $this->sendBlockChanged($userId);

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNotNull($session, 'Nothing rotated: nobody was signed in');
        $this->assertNull($session->userId);
        $this->assertNull($session->blockedUserId);
        $this->assertSame($userId, $session->pendingSecondFactorUserId);
        $this->assertSame(SecondFactorPendingMode::VERIFY, $session->pendingSecondFactorMode);
        $response = $this->lastHandshakeResponseFor('held-ak');
        $this->assertNull($response?->accountBlocked);
        $this->assertSame(AuthFlowStep::SECOND_FACTOR, $response?->pendingAuthStep['step'] ?? null);
    }

    /**
     * A sign-in refused over a card the block raised on a signed-in browser keeps "was inside", so no code is asked.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testARefusedSignInOverAWasInsideCardDoesNotLowerIt(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->enrolSecondFactor($userId);
        $this->signedInSession('inside-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $this->grant($this->sessionOf('inside-ak')->token, $userId, 'inside-ak');
        $this->assertSame($userId, $this->sessionOf('inside-ak')?->blockedUserId);
        $this->assertTrue($this->sessionOf('inside-ak')?->blockedSignedIn, 'Proving the password again lowers nothing');

        $this->unblock($userId);
        $this->sendBlockChanged($userId);

        $session = $this->sessionOf('inside-ak');
        $this->assertSame($userId, $session?->userId);
        $this->assertNull($session?->pendingSecondFactorUserId, 'A browser that was inside is not asked for a code');
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

        $session = $this->sessionOf('door-ak-2');
        $this->assertNotNull($session);
        $this->assertNotSame($token, $session->token);
        $this->assertNull($session?->userId);
        $this->assertSame($userId, $session?->blockedUserId);
        $this->assertNotNull($this->lastHandshakeResponseFor('door-ak-2')?->accountBlocked);

        $sessionId = $session->id;
        $this->deliverHandshake($this->holder, $this->handshake('door-ak-3', $session->token));
        $this->assertSame($sessionId, $this->sessionOf('door-ak-3')?->id);
        $this->assertSame($userId, $this->sessionOf('door-ak-3')?->blockedUserId);
        $this->assertNotNull($this->lastHandshakeResponseFor('door-ak-3')?->accountBlocked);
    }

    /**
     * A browser that had no live tab when the block was lifted keeps its card, and is signed back in at the door.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testTheHandshakeDoorSignsAnAbsentBrowserBackIn(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('away-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $card = $this->sessionOf('away-ak');
        $this->assertNotNull($card);
        $cardId = $card->id;
        $cardToken = $card->token;
        Hilos::$rt->connections['away-ak']->actions->unregister();

        $this->unblock($userId);
        $this->sendBlockChanged($userId);
        $kept = Hilos::$db->sessions->findByToken($cardToken);
        $this->assertSame($userId, $kept?->blockedUserId, 'With no tab to hand a cookie to, the frame leaves the row alone');
        $this->assertNull($kept?->userId);
        $this->drainSignals();

        $this->deliverHandshake($this->holder, $this->handshake('away-ak-2', $cardToken));

        $session = $this->sessionOf('away-ak-2');
        $this->assertNotNull($session);
        $this->assertSame($cardId, $session->id);
        $this->assertNotSame($cardToken, $session->token);
        $this->assertSame($userId, $session->userId);
        $this->assertNull($session->blockedUserId);
        $this->assertNotNull($this->rotationOnto($session->token), 'The ticket rides the handshake');
        $response = $this->lastHandshakeResponseFor('away-ak-2');
        $this->assertSame($userId, $response?->selfId);
        $this->assertNull($response?->accountBlocked);
    }

    /**
     * A browser refused at sign-in and away when the block was lifted meets its code step at the door.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testTheHandshakeDoorSendsARefusedBrowserToItsSecondFactor(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->enrolSecondFactor($userId);
        $this->block($userId);
        $token = $this->anonymousSession('away-held-ak');
        $this->grant($token, $userId, 'away-held-ak');
        Hilos::$rt->connections['away-held-ak']->actions->unregister();
        $this->unblock($userId);
        $this->sendBlockChanged($userId);
        $this->drainSignals();

        $this->deliverHandshake($this->holder, $this->handshake('away-held-ak-2', $token));

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNotNull($session);
        $this->assertNull($session->userId);
        $this->assertNull($session->blockedUserId);
        $this->assertSame($userId, $session->pendingSecondFactorUserId);
        $response = $this->lastHandshakeResponseFor('away-held-ak-2');
        $this->assertNull($response?->accountBlocked);
        $this->assertSame(AuthFlowStep::SECOND_FACTOR, $response?->pendingAuthStep['step'] ?? null);
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
        $this->enrolSecondFactor($userId);
        $this->block($userId);
        $token = $this->anonymousSession('grant-ak');
        $this->drainSignals();

        $outcome = $this->grant($token, $userId, 'grant-ak');

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNotNull($session, 'Nothing rotated: nobody was signed in');
        $this->assertNull($session->userId);
        $this->assertNull($session->pendingSecondFactorUserId, 'The second factor does not hold a refused sign-in');
        self::assertFalse(Hilos::$db->stepUps->isConfirmed(
            StateProtectedModeRuntime::hashSessionToken($token), $userId, StepUpOperationKey::EXPORT_DATA,
        ));
        $this->assertSame($userId, $session->blockedUserId);
        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->ok);
        $this->assertSame(AuthFlowOutcome::CODE_ACCOUNT_BLOCKED, $outcome->code);
        $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
        $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
        $this->assertSame(['identifier' => $email, 'dataExport' => null], $this->lastHandshakeResponseFor('grant-ak')?->accountBlocked);
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
        self::assertFalse(Hilos::$db->stepUps->isConfirmed(
            StateProtectedModeRuntime::hashSessionToken($token), $userId, StepUpOperationKey::EXPORT_DATA,
        ));
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
        $currentSessionId = Hilos::$db->sessions->findByToken($token)->id;
        $this->drainSignals();

        $library = $this->sessionsLibrary();
        $otherSessionId = $this->underAgent($library, static function () use ($userId, $currentSessionId): int {
            $other = Hilos::$db->sessions->actions->createAnonymous(RandomHelper::hex(16));
            $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
            Hilos::$db->secondFactorTrusts->actions->trust($currentSessionId, $userId, $until);
            Hilos::$db->secondFactorTrusts->actions->trust($other->id, $userId, $until);

            return $other->id;
        });
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
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($currentSessionId, $userId, 30));
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($otherSessionId, $userId, 30));
    }

    /**
     * A password recovered during the block cancels the return of every other browser, and the recovering one keeps its card.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testARecoveredPasswordCancelsTheReturnOfOtherBrowsers(): void
    {
        $email = $this->uniqueEmail();
        $userId = $this->registerUser($email);
        $this->signedInSession('left-behind-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $token = $this->anonymousSession('recovering-ak');
        $this->drainSignals();

        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(
            new AgentSignalData(new AuthPasswordChangedSignalData(
                userId: $userId,
                sessionToken: $token,
                acceptKey: 'recovering-ak',
                identifier: $email,
                requestId: self::REQUEST_ID,
                action: HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET,
            )),
            '',
            HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED,
        ));
        $this->deliverLibraryFrames($this->holder);

        $this->assertNull($this->sessionOf('left-behind-ak')?->blockedUserId, 'The other browser can no longer come back');
        $recovering = Hilos::$db->sessions->findByToken($token);
        $this->assertSame($userId, $recovering?->blockedUserId);
        $this->assertFalse($recovering?->blockedSignedIn);

        $this->unblock($userId);
        $this->sendBlockChanged($userId);

        $this->assertNull($this->sessionOf('left-behind-ak')?->userId);
        $this->assertSame($userId, $this->sessionOf('recovering-ak')?->userId);
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
        $this->assertNotNull($this->sessionOf('dismiss-ak'));
        $this->assertNotSame($token, $this->sessionOf('dismiss-ak')->token);
        $this->assertNull($this->sessionOf('dismiss-ak')->blockedUserId);
        $this->assertNotNull($answer, 'The press is answered on the tab that pressed');
        $this->assertNull($answer->accountBlocked);

        $again = $this->dismiss('dismiss-ak');
        $this->assertNotNull($again, 'A press with no card left is answered as well');
        $this->assertNotNull($this->sessionOf('dismiss-ak'));
        $this->assertNotSame($token, $this->sessionOf('dismiss-ak')->token);
        $this->assertNull($this->sessionOf('dismiss-ak')->blockedUserId);

        $this->unblock($userId);
        $this->sendBlockChanged($userId);
        $this->assertNull($this->sessionOf('dismiss-ak')?->userId, 'The person signed out on the card: nobody comes back');
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

        $this->assertNull(Hilos::$db->sessions->findByToken($token));
        $this->grant($this->sessionOf('switch-ak')->token, $otherId, 'switch-ak');

        $session = $this->sessionOf('switch-ak');
        $this->assertSame($otherId, $session?->userId);
        $this->assertNull($session?->blockedUserId);
        $this->assertNull($this->lastHandshakeResponseFor('switch-ak')?->accountBlocked);

        $this->unblock($blockedId);
        $this->sendBlockChanged($blockedId);
        $this->assertSame($otherId, $this->sessionOf('switch-ak')?->userId, 'The browser went elsewhere: nobody comes back');
    }

    /**
     * A refused password login credits export, and the copy owner accepts only that browser's person.
     *
     * @throws HilosException When a fixture, order or forget frame fails
     */
    public function testBlockedPasswordCreditsExportAndOrderReplacesItsCopy(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->block($userId);
        $token = $this->anonymousSession('export-credit-ak');
        $this->grant($token, $userId, 'export-credit-ak');
        self::assertTrue(Hilos::$db->stepUps->isConfirmed(
            StateProtectedModeRuntime::hashSessionToken($token), $userId, StepUpOperationKey::EXPORT_DATA,
        ));
        $opening = $this->usersLibrary()->onAgentAction(
            'export-credit-ak', HilosSignalConstants::HILOS_STEP_UP_START,
            new StepUpStartActionDTO(StepUpOperationKey::EXPORT_DATA),
        );
        self::assertInstanceOf(StepUpOpeningReplyDTO::class, $opening);
        self::assertFalse($opening->required);
        self::assertSame(DataExportGroup::forUser($userId), Hilos::$sr->groupSubscriptionName('export-credit-ak', DataExportGroup::NAME));

        $path = sys_get_temp_dir() . '/hil303-order-' . bin2hex(random_bytes(8));
        $previousFs = Hilos::$fs;
        Hilos::$fs = new class($path) extends FsContext {
            /** @param string $path Private archive directory */
            public function __construct(private readonly string $path) { }
            /** Registers only the directory this order test needs. */
            public function configure(): void { $this->registerDirectory(self::DATA_EXPORT, $this->path, DirectoryScope::CLUSTER); }
        };
        Hilos::$fs->configure();
        $agent = new DataExportAgent();
        try {
            $this->startAgent($agent);
            $order = static fn () => $agent->onAgentAction(
                'export-credit-ak', HilosSignalConstants::HILOS_DATA_EXPORT_ORDER, new DataExportOrderActionDTO(),
            );
            $this->underAgent($agent, $order);
            $first = Hilos::$db->dataExports->ofUser($userId);
            self::assertNotNull($first);
            self::assertSame(DataExportState::PREPARING, $first->state);
            $this->underAgent($agent, $order);
            self::assertSame($first->id, Hilos::$db->dataExports->ofUser($userId)?->id);
            // WorkerManager flushes the order's ack only after this first tick returns.
            $this->underAgent($agent, static fn () => $agent->onTick());
            self::assertSame(DataExportState::PREPARING, $first->state, 'The reply must leave before archive assembly starts');
            self::assertSame([], glob($path . '/*'), 'No archive I/O before the worker can flush the reply');
            $this->underAgent($agent, static fn () => $agent->onTick());
            self::assertSame(DataExportState::READY, $first->state, 'The next worker turn builds the request');
            $readyPath = $path . '/' . $first->storedName;
            self::assertFileExists($readyPath);
            $this->underAgent($agent, $order);
            self::assertFileDoesNotExist($readyPath);
            self::assertNotSame($first->id, Hilos::$db->dataExports->ofUser($userId)?->id);
            $this->underAgent($agent, static function () use ($userId): void {
                Hilos::$db->dataExports->ofUser($userId)?->actions->delete();
            });
            $this->underAgent($agent, static fn () => $agent->onSignalAgent(
                new AgentSignalData(new DataExportForgetUserSignalData($userId)), '',
                HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER,
            ));
            self::assertNull(Hilos::$db->dataExports->ofUser($userId));
            self::assertSame([], glob($path . '/*'));
        } finally {
            Hilos::$fs = $previousFs;
            foreach (glob($path . '/*') as $file) { unlink($file); }
            if (is_dir($path)) { rmdir($path); }
            TruthSourceRegistry::unregisterAgent($agent->getId());
        }
    }

    /**
     * A card raised by session loss asks for proof and cannot open a different operation.
     *
     * @throws HilosException When the fixture or confirmation fails
     */
    public function testLostSessionCardAsksForProofOnlyForExport(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('export-ask-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $opening = $this->usersLibrary()->onAgentAction(
            'export-ask-ak', HilosSignalConstants::HILOS_STEP_UP_START,
            new StepUpStartActionDTO(StepUpOperationKey::EXPORT_DATA),
        );
        self::assertInstanceOf(StepUpOpeningReplyDTO::class, $opening);
        self::assertTrue($opening->required);
        self::assertSame(StepUpMethod::PASSWORD, $opening->method);
        $this->usersLibrary()->onAgentAction(
            'export-ask-ak', HilosSignalConstants::HILOS_STEP_UP_CONFIRM,
            new StepUpConfirmActionDTO(StepUpOperationKey::EXPORT_DATA, StepUpMethod::PASSWORD, '', false,
                'a long enough passphrase', null),
        );
        self::assertTrue(Hilos::$db->stepUps->isConfirmed(
            StateProtectedModeRuntime::hashSessionToken($this->sessionOf('export-ask-ak')->token),
            $userId, StepUpOperationKey::EXPORT_DATA,
        ));
        $this->expectException(ItemNotFoundForUpdateException::class);
        $this->usersLibrary()->onAgentAction(
            'export-ask-ak', HilosSignalConstants::HILOS_STEP_UP_START,
            new StepUpStartActionDTO(StepUpOperationKey::CHANGE_PASSWORD),
        );
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
     * Gives the person a confirmed authenticator, so the second-factor gate holds their sign-ins.
     *
     * @param int $userId Person to enrol
     * @throws HilosException When the factor write fails
     */
    private function enrolSecondFactor(int $userId): void
    {
        Hilos::$db->secondFactors->actions
            ->startEnrolment($userId, 'Phone', Base32::encode(self::SECRET_BYTES))
            ->actions->confirm('Phone');
    }

    /**
     * Runs one act and returns the `account_block_lifted` line it logged, decoded.
     *
     * @param callable(): mixed $act Act that may lift a block
     * @return ?array<string, mixed> Logged fields, or null when the act logged no such line
     * @throws JsonException When the logged fields are not JSON
     */
    private function liftedLogLine(callable $act): ?array
    {
        ob_start();
        try {
            $act();
        } finally {
            $output = (string) ob_get_clean();
        }

        if (preg_match('/account_block_lifted (\{.*\})/', $output, $match) !== 1) {
            return null;
        }

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Finds the rotation that hands a browser the given session token, if one was announced.
     *
     * @param string $sessionToken Token the rotation moves the browser onto
     * @return ?HilosSessionRotation Announced rotation, or null when none names the token
     */
    private function rotationOnto(string $sessionToken): ?HilosSessionRotation
    {
        foreach (Hilos::$rt->hilosSessionRotations as $rotation) {
            if ($rotation->sessionToken === $sessionToken) {
                return $rotation;
            }
        }

        return null;
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
            provenBy: StepUpMethod::PASSWORD,
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
