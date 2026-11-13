<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\DataExportAgent;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripOpenedSignalData;
use Hilos\Auth\OAuth\OAuthStateSigner;
use Hilos\Auth\SecondFactor\Base32;
use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Auth\Session\DTO\OAuthResumeActionDTO;
use Hilos\Auth\Session\DTO\OAuthResumeReplyDTO;
use Hilos\Auth\StepUp\StepUpMethod;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\DataExport\DTO\DataExportOrderActionDTO;
use Hilos\Database\Database;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AdminCommandConstants;
use Hilos\Users\DTO\UserBrowserTrustRenewDoneSignalData;
use Hilos\Users\DTO\UserBrowserTrustRenewSignalData;
use Hilos\Users\DTO\UserPasskeyUseDoneSignalData;
use Hilos\Users\DTO\UserPasskeyUseSignalData;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\TimeHelper;
use JsonException;

/**
 * Integration tests for the sign-in doors of the sessions library once a person's erasure has begun (HIL-1939).
 *
 * Driven in chat beside the block's own doors ({@see AccountBlockEnforcementTest}), whose cases
 * these follow. The start of an erasure is a fact on the person's deletion request, and nothing
 * writes it in a running project yet, so a case writes `started_at` straight into the table - the
 * fact the holder reads with fresh SQL on every door.
 *
 * Coverage: a password sign-in is refused ahead of the second factor, in the erasing words, with
 * no card and no credit, and so is a code typed after the erasure began over a first factor shown
 * before it; a passkey sign-in is refused by the person's agent and binds nothing; a provider
 * sign-in is answered with its own reason, on a live tab and when a held grant is applied; a
 * recovered password binds nothing and still takes the other sessions; an unblock and a returning
 * tab with the card give nobody back, and a takeover of the person is refused; a trust the
 * person's agent found no pair for is not written again; a copy of the person's data is not
 * ordered, from a signed-in tab or from the card (HIL-1946).
 *
 * Requires the test DB reset before run (composer run test:db-reset).
 */
final class AccountErasingSignInTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string REQUEST_ID = 'req-1939';

    private const string SECRET_BYTES = 'hil-1939-secret-bytes';

    private const string PUBLIC_KEY_PEM = "-----BEGIN PUBLIC KEY-----\nstub\n-----END PUBLIC KEY-----\n";

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
     * @throws HilosException When a runtime clear fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->hilosOAuthTrips->actions->forgetEnded(-1);
        parent::tearDown();
    }

    /**
     * A proven password is refused ahead of the second factor: nothing bound, no card, no credit.
     *
     * @throws HilosException When setup or a frame fails
     * @throws JsonException When the log line cannot be decoded
     */
    public function testAPasswordSignInIsRefusedAheadOfTheSecondFactor(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->enrolSecondFactor($userId);
        $this->beginErasure($userId);
        $token = $this->anonymousSession('grant-ak');
        $sessionId = Hilos::$db->sessions->findByToken($token)?->id;
        $this->drainSignals();

        $outcome = null;
        $line = $this->refusedLogLine(function () use ($token, $userId, &$outcome): void {
            $outcome = $this->grant($token, $userId, 'grant-ak');
        });

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNotNull($session, 'Nothing rotated: nobody was signed in');
        $this->assertNull($session->userId);
        $this->assertNull($session->pendingSecondFactorUserId, 'The second factor does not hold a refused sign-in');
        $this->assertNull($session->blockedUserId, 'No "Access closed" card');
        self::assertFalse(Hilos::$db->stepUps->isConfirmed(
            StateProtectedModeRuntime::hashSessionToken($token), $userId, StepUpOperationKey::EXPORT_DATA,
        ));
        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->ok);
        $this->assertSame(AuthFlowOutcome::CODE_ACCOUNT_ERASING, $outcome->code);
        $this->assertSame(AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE, $outcome->message);
        $this->assertSame(AuthFlowStep::IDENTIFIER, $outcome->step);
        $this->assertSame(AuthFlowIntent::LOGIN, $outcome->intent);
        $this->assertSame(['event' => 'account_erasing_refused', 'user' => $userId, 'session' => $sessionId], $line);
    }

    /**
     * A first factor shown before the erasure began and a code typed after it: the code is refused too.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testACodeTypedAfterTheStartIsRefused(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->enrolSecondFactor($userId);
        $token = $this->anonymousSession('code-ak');
        $this->grant($token, $userId, 'code-ak');
        $this->assertSame(
            $userId,
            Hilos::$db->sessions->findByToken($token)?->pendingSecondFactorUserId,
            'The first factor waits on the code step',
        );
        $this->beginErasure($userId);
        $this->drainSignals();

        $outcome = $this->toLibrary(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, new AuthSessionGrantSignalData(
            sessionToken: $token,
            userId: $userId,
            acceptKey: 'code-ak',
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::HILOS_LOGIN,
            secondFactorProven: true,
        ));

        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNotNull($session);
        $this->assertNull($session->userId);
        $this->assertNull($session->pendingSecondFactorUserId, 'The wait is let go');
        $this->assertNull($session->blockedUserId);
        $this->assertSame(AuthFlowOutcome::CODE_ACCOUNT_ERASING, $outcome?->code);
    }

    /**
     * A passkey sign-in never reaches the holder: the person's agent refuses the use, and nothing is bound.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAPasskeySignInIsRefusedByThePersonsAgent(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $passkeyId = $this->seedPasskey($userId);
        $this->beginErasure($userId);
        $token = $this->anonymousSession('passkey-ak');
        $this->drainSignals();

        $agent = $this->personAgent($userId);
        $this->underAgent($agent, static fn () => $agent->onSignalAgent(new AgentSignalData(new UserPasskeyUseSignalData(
            userId: $userId,
            passkeyId: $passkeyId,
            signCount: 1,
            operation: null,
            sessionTokenHash: null,
            replySignal: HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
            acceptKey: 'passkey-ak',
            requestId: self::REQUEST_ID,
            action: HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM,
            successMessage: null,
        )), '', HilosSignalConstants::HILOS_USER_PASSKEY_USE));
        $done = $this->takeFrame(HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE);
        $this->assertInstanceOf(UserPasskeyUseDoneSignalData::class, $done);
        $this->assertSame(AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE, $done->error);

        $library = $this->usersLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(
            new AgentSignalData($done),
            '',
            HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE,
        ));

        $this->assertNull($this->takeFrame(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT), 'The holder is not asked to sign in');
        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
    }

    /**
     * A provider sign-in on a live tab ends the trip in the erasing reason, with the erasing words.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAProviderSignInOnALiveTabEndsTheTripAsErasing(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->beginErasure($userId);
        $token = $this->anonymousSession('trip-ak');
        $tripKeyHash = StateHilosOAuthTrip::hashKey(RandomHelper::hex(16));
        $this->openTrip($tripKeyHash, $token, 'trip-ak');

        $this->toLibrary(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, new AuthSessionGrantSignalData(
            sessionToken: $token,
            userId: $userId,
            acceptKey: 'trip-ak',
            tripKeyHash: $tripKeyHash,
        ));

        $this->assertSame(OAuthResultSignalData::REASON_ACCOUNT_ERASING, Hilos::$rt->hilosOAuthTrips[$tripKeyHash]?->ending);
        $result = $this->lastOAuthResult('trip-ak');
        $this->assertSame(OAuthResultSignalData::REASON_ACCOUNT_ERASING, $result?->reason);
        $this->assertSame(AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE, $result?->message);
        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertNull($session?->userId);
        $this->assertNull($session?->blockedUserId);
    }

    /**
     * A provider sign-in held for an absent tab is refused when the tab comes back after the erasure began.
     *
     * The first ending of a trip wins, so the held grant keeps its record; the tab that presents
     * the key is told the erasing reason, and nothing is bound.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testAHeldProviderSignInEndsTheTripAsErasing(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $token = $this->anonymousSession('held-ak');
        $tripKey = RandomHelper::hex(16);
        $tripKeyHash = StateHilosOAuthTrip::hashKey($tripKey);
        $this->openTrip($tripKeyHash, $token, 'held-ak');
        Hilos::$rt->connections['held-ak']->actions->unregister();
        $this->toLibrary(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, new AuthSessionGrantSignalData(
            sessionToken: $token,
            userId: $userId,
            acceptKey: 'held-ak',
            tripKeyHash: $tripKeyHash,
        ));
        $this->assertSame(StateHilosOAuthTrip::ENDING_GRANTED, Hilos::$rt->hilosOAuthTrips[$tripKeyHash]?->ending);
        $this->beginErasure($userId);
        $this->deliverHandshake($this->holder, $this->handshake('held-ak-2', $token));
        $this->drainSignals();

        $library = $this->sessionsLibrary();
        $reply = $this->underAgent($library, static fn () => $library->onAgentAction(
            'held-ak-2',
            HilosSignalConstants::HILOS_OAUTH_RESUME,
            new OAuthResumeActionDTO($tripKey),
        ));
        $this->deliverLibraryFrames($this->holder);

        $this->assertInstanceOf(OAuthResumeReplyDTO::class, $reply);
        $this->assertTrue($reply->known);
        $result = $this->lastOAuthResult('held-ak-2');
        $this->assertSame(OAuthResultSignalData::REASON_ACCOUNT_ERASING, $result?->reason);
        $this->assertSame(AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE, $result?->message);
        $this->assertNull(Hilos::$db->sessions->findByToken($token)?->userId);
    }

    /**
     * A recovered password binds nothing and opens no panel, and every other session of the person still goes.
     *
     * The reset itself is refused by the person's agent; the frame is handed to the holder directly,
     * as the race where the reset was written before the erasure began would hand it.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testARecoveredPasswordDoesNotSignAnErasingPersonIn(): void
    {
        $email = $this->uniqueEmail();
        $userId = $this->registerUser($email);
        $otherToken = $this->signedInSession('elsewhere-ak', $userId);
        $this->beginErasure($userId);
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
        $this->assertNotNull($session);
        $this->assertNull($session->userId);
        $this->assertNull($session->pendingAck, 'No "password changed" panel');
        $this->assertNull($session->blockedUserId);
        $this->assertSame(AuthFlowOutcome::CODE_ACCOUNT_ERASING, $outcome?->code);
        $this->assertNull(Hilos::$db->sessions->findByToken($otherToken)?->userId, 'The other session goes, as a reset promises');
    }

    /**
     * An unblock gives the card's tab nothing back once the erasure began: the card is only lowered.
     *
     * @throws HilosException When setup or a frame fails
     * @throws JsonException When the log line cannot be decoded
     */
    public function testAnUnblockGivesAnErasingPersonNothingBack(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('unblock-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $cardId = $this->sessionOf('unblock-ak')?->id;
        $this->assertSame($userId, $this->sessionOf('unblock-ak')?->blockedUserId);
        $this->beginErasure($userId);

        $this->unblock($userId);
        $line = $this->liftedLogLine(fn () => $this->sendBlockChanged($userId));

        $session = $this->sessionOf('unblock-ak');
        $this->assertNotNull($session);
        $this->assertSame($cardId, $session->id);
        $this->assertNull($session->userId);
        $this->assertNull($session->blockedUserId);
        $this->assertSame([
            'event' => 'account_block_lifted',
            'user' => $userId,
            'returned' => [],
            'secondFactor' => [],
            'lowered' => [$cardId],
        ], $line);
    }

    /**
     * A tab that comes back to the card after the unblock is not signed in once the erasure began.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testATabWithTheCardIsNotSignedBackIn(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('away-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $cardToken = $this->sessionOf('away-ak')?->token;
        $this->assertNotNull($cardToken);
        Hilos::$rt->connections['away-ak']->actions->unregister();
        $this->beginErasure($userId);
        $this->unblock($userId);
        $this->drainSignals();

        $this->deliverHandshake($this->holder, $this->handshake('away-ak-2', $cardToken));

        $session = $this->sessionOf('away-ak-2');
        $this->assertNotNull($session);
        $this->assertSame($cardToken, $session->token, 'Nothing rotated');
        $this->assertNull($session->userId);
        $this->assertNull($session->blockedUserId, 'The card is lowered');
        $response = $this->lastHandshakeResponseFor('away-ak-2');
        $this->assertNull($response?->selfId);
        $this->assertNull($response?->accountBlocked);
    }

    /**
     * An administrator may not take over a person whose erasure has begun.
     *
     * @throws HilosException When setup or the command fails
     */
    public function testATakeoverOfAnErasingPersonIsRefused(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->beginErasure($userId);
        $adminId = (int) Hilos::$db->users->actions->createWithName('Admin')->id;
        Hilos::$db->users[$adminId]->actions->setAdmin(true);
        $token = $this->signedInSession('admin-ak', $adminId);
        $this->drainSignals();

        $library = $this->sessionsLibrary();
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId()),
            static function () use ($library, $token, $userId): void {
                $library->onSignalCommand(new CommandRequestDTO(
                    correlationId: RandomHelper::hex(8),
                    command: CliCommands::IMPERSONATE_START,
                    payload: [
                        AdminCommandConstants::FIELD_SESSION_TOKEN => $token,
                        AdminCommandConstants::FIELD_TARGET_USER_ID => $userId,
                    ],
                ), '', '');
            },
        );

        $reply = $this->commandReply();
        $this->assertFalse($reply->isOk(), 'The takeover of an erasing account went through');
        $this->assertSame(
            AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE,
            $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null,
        );
        $session = Hilos::$db->sessions->findByToken($token);
        $this->assertSame($adminId, $session?->userId);
        $this->assertNull($session?->impersonatorUserId);
    }

    /**
     * A trust the person's agent found no pair for is written again for a person, and not once the erasure began.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testATrustIsNotWrittenAgainForAnErasingPerson(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->enrolSecondFactor($userId);
        $beforeId = (int) Hilos::$db->sessions->findByToken($this->signedInSession('trust-ak', $userId))?->id;
        $afterId = (int) Hilos::$db->sessions->findByToken($this->signedInSession('trust-ak-2', $userId))?->id;

        $this->renewalNotFound($userId, $beforeId);
        $this->assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($beforeId, $userId, 30), 'Before the start the pair is written again');

        $this->beginErasure($userId);
        $this->renewalNotFound($userId, $afterId);
        $this->assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($afterId, $userId, 30));
    }

    /**
     * A signed-in tab of a person whose erasure has begun cannot order a copy of their data.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testASignedInTabCannotOrderACopyOnceTheErasureBegan(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('export-signed-in-ak', $userId);
        $this->beginErasure($userId);

        $this->assertCopyOrderRefused('export-signed-in-ak', $userId);
    }

    /**
     * The session showing the "Access closed" card of a person whose erasure has begun cannot order a copy either.
     *
     * @throws HilosException When setup or a frame fails
     */
    public function testATabWithTheCardCannotOrderACopyOnceTheErasureBegan(): void
    {
        $userId = $this->registerUser($this->uniqueEmail());
        $this->signedInSession('export-card-ak', $userId);
        $this->block($userId);
        $this->sendBlockChanged($userId);
        $this->assertSame($userId, $this->sessionOf('export-card-ak')?->blockedUserId, 'The tab shows the card');
        $this->beginErasure($userId);

        $this->assertCopyOrderRefused('export-card-ak', $userId);
    }

    /**
     * Orders a copy from one tab through the data copy agent, in its frame, and expects the erasing words with no order written.
     *
     * @param string $acceptKey Tab ordering the copy
     * @param int $userId Person whose erasure has begun
     * @throws HilosException When the agent cannot start, or the order fails other than by the refusal
     */
    private function assertCopyOrderRefused(string $acceptKey, int $userId): void
    {
        $path = sys_get_temp_dir() . '/hil1946-order-' . bin2hex(random_bytes(8));
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
            try {
                $this->underAgent($agent, static fn () => $agent->onAgentAction(
                    $acceptKey, HilosSignalConstants::HILOS_DATA_EXPORT_ORDER, new DataExportOrderActionDTO(),
                ));
                $this->fail('A copy was ordered for a person whose erasure has begun');
            } catch (ValidationException $refusal) {
                $this->assertSame(AbstractSessionsLibraryAgent::ERASING_ACCOUNT_REFUSED_MESSAGE, $refusal->getMessage());
            }
            $this->assertSame([], Database::sql('SELECT `id` FROM `hilos_data_export` WHERE `user_id` = ?', [$userId])->rows());
        } finally {
            Hilos::$fs = $previousFs;
            try {
                foreach (glob($path . '/*') as $file) { unlink($file); }
                if (is_dir($path)) { rmdir($path); }
            } finally {
                TruthSourceRegistry::unregisterAgent($agent->getId());
            }
        }
    }

    /**
     * Writes the start of the person's erasure as a fact on a standing deletion request.
     *
     * @param int $userId Person whose erasure begins
     * @throws HilosException When the row cannot be written
     */
    private function beginErasure(int $userId): void
    {
        $now = TimeHelper::nowMs();
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`, `started_at`) VALUES (?, ?, ?, ?)',
            [$userId, $now, $now + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, $now],
        );
        $this->assertNotNull(Hilos::$db->accountDeletions->startedOf($userId));
    }

    /**
     * Registers a person with a confirmed address.
     *
     * @param string $email Confirmed address of the person
     * @return int New user id
     * @throws HilosException When the user or identity write fails
     */
    private function registerUser(string $email): int
    {
        $userId = (int) Hilos::$db->users->actions->createWithName('Erasing Candidate')->id;
        Hilos::$db->identities->createPasswordIdentity($userId, $email, 'a long enough passphrase')->markVerified();

        return $userId;
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
     * Stores a device key of the person the way the ceremony does: the anchor row, then the key.
     *
     * @param int $userId Owner of the key
     * @return int Row of the key
     * @throws HilosException When the anchor or the key cannot be stored
     */
    private function seedPasskey(int $userId): int
    {
        $credentialId = RandomHelper::hex(16);
        $anchorId = Hilos::$db->identities->createPasskeyIdentity($userId, $credentialId)->id;
        $this->assertNotNull($anchorId);

        return (int) Hilos::$db->passkeyCredentials->createFromRegistration(
            $anchorId,
            $userId,
            $credentialId,
            self::PUBLIC_KEY_PEM,
            PasskeyAlgorithm::Es256,
            0,
            null,
            null,
            RandomHelper::hex(16),
            null,
        )->id;
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
     * Hands the library the answer of the person's agent that it found no pair to extend.
     *
     * @param int $userId Person the browser was trusted for
     * @param int $sessionId Session row of the browser
     * @throws HilosException When the frame fails
     */
    private function renewalNotFound(int $userId, int $sessionId): void
    {
        $now = TimeHelper::nowMs();
        $this->toLibrary(HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE, new UserBrowserTrustRenewDoneSignalData(
            new UserBrowserTrustRenewSignalData(
                $userId,
                $sessionId,
                $now + 30 * TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND,
                $now,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE,
            ),
            false,
            null,
            null,
            null,
        ));
    }

    /**
     * Opens a provider sign-in for a session on one connection.
     *
     * @param string $tripKeyHash Hash of the trip's key
     * @param string $token Session the trip was started under
     * @param string $acceptKey Connection the trip is owed to
     * @throws HilosException When the frame fails
     */
    private function openTrip(string $tripKeyHash, string $token, string $acceptKey): void
    {
        $this->toLibrary(HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED, new OAuthTripOpenedSignalData(
            tripKeyHash: $tripKeyHash,
            sessionTokenHash: StateProtectedModeRuntime::hashSessionToken($token),
            acceptKey: $acceptKey,
            mode: OAuthStateSigner::MODE_LOGIN,
            provider: 'google',
        ));
        $this->drainSignals();
    }

    /**
     * Hands the library one frame addressed to it and runs what it queued through the holder.
     *
     * @param string $name Frame name
     * @param SignalDataInterface $frame Payload
     * @return ?AuthFlowOutcome Outcome the last state frame handed over, or null when none carried one
     * @throws HilosException When the frame or what follows it fails
     */
    private function toLibrary(string $name, SignalDataInterface $frame): ?AuthFlowOutcome
    {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $library->onSignalAgent(new AgentSignalData($frame), '', $name));
        $outcome = $this->deliverLibraryFrames($this->holder);
        $this->deliverPersonAgentFrames();

        return $outcome;
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
     * Drains the queue and returns the payload of the first agent frame under one name.
     *
     * @param string $name Frame name
     * @return ?SignalDataInterface The frame's payload, or null when none was queued
     */
    private function takeFrame(string $name): ?SignalDataInterface
    {
        $found = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($found === null && $signal->signalName->getName() === $name && $signal->data instanceof AgentSignalData) {
                $found = $signal->data->data;
            }
        }

        return $found;
    }

    /**
     * Drains the queue and returns the last provider result sent to one connection.
     *
     * @param string $acceptKey Connection the result is addressed to
     * @return ?OAuthResultSignalData The last result, or null when none was sent
     */
    private function lastOAuthResult(string $acceptKey): ?OAuthResultSignalData
    {
        $result = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $data = $signal->data;
            if ($data instanceof WebSocketSignalData
                && $data->targetAcceptKey === $acceptKey
                && $data->data instanceof OAuthResultSignalData) {
                $result = $data->data;
            }
        }

        return $result;
    }

    /**
     * Drains the queue and returns the one reply the operator was given.
     *
     * @return CommandReplyDTO The reply
     */
    private function commandReply(): CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        $this->assertCount(1, $replies);

        return $replies[0];
    }

    /**
     * Runs one act and returns the `account_erasing_refused` line it logged, decoded.
     *
     * @param callable(): mixed $act Act that may refuse a sign-in
     * @return ?array<string, mixed> Logged fields, or null when the act logged no such line
     * @throws JsonException When the logged fields are not JSON
     */
    private function refusedLogLine(callable $act): ?array
    {
        return $this->loggedLine('account_erasing_refused', $act);
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
        return $this->loggedLine('account_block_lifted', $act);
    }

    /**
     * Runs one act and returns the fields of one log event it wrote, decoded.
     *
     * @param string $event Event the line opens with
     * @param callable(): mixed $act Act that may log it
     * @return ?array<string, mixed> Logged fields, or null when the act logged no such line
     * @throws JsonException When the logged fields are not JSON
     */
    private function loggedLine(string $event, callable $act): ?array
    {
        ob_start();
        try {
            $act();
        } finally {
            $output = (string) ob_get_clean();
        }

        if (preg_match('/' . preg_quote($event, '/') . ' (\{.*\})/', $output, $match) !== 1) {
            return null;
        }

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return string Unique lowercase address for one person
     */
    private function uniqueEmail(): string
    {
        return 'erasing-' . RandomHelper::hex(6) . '@example.test';
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
