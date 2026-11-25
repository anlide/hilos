<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferClearedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferReceivedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferWakeSignalData;
use Demo\EcommerceShop\Core\Router\DTO\ShopAccountMergeCartWakeSignalData;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\View\Item\Session;
use Demo\EcommerceShop\Database\Object\Item\SessionVoucherCart;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergeCancellationState;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeCoordinator;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeOpening;
use Hilos\Auth\AccountOperation\AccountOperationAnswerKind;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountMergePersonalHandoverSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
use Hilos\Auth\AccountOperation\Exception\AccountMergedSignInException;
use Hilos\Auth\Flow\AuthFlowIntent;
use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Flow\AuthFlowStep;
use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Library\DTO\AuthPasswordChangedSignalData;
use Hilos\Auth\Library\DTO\AuthRegistrationLandedSignalData;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\LoginActionDTO;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripOpenedSignalData;
use Hilos\Auth\Session\DTO\AccountBlockChangedSignalData;
use Hilos\Auth\Session\DTO\OAuthResumeActionDTO;
use Hilos\Auth\Session\DTO\OAuthResumeReplyDTO;
use Hilos\Auth\Session\DTO\SessionRebindSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Auth\Session\SessionCookieName;
use Hilos\Auth\Session\SessionToken;
use Hilos\Auth\TestUserCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\SessionActions as FrameworkSessionActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Runtime\State\Item\HilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionRotation;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Users\AdminCommandConstants;
use Hilos\Users\DTO\UserAdminCommandDoneSignalData;
use Hilos\Users\DTO\UserAdminCommandSignalData;
use Hilos\Users\DTO\UserAdminCreateDoneSignalData;
use Hilos\Users\DTO\UserAdminCreateSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Session assignment and transfer delivery run under the agents' actual ownership claims. */
final class GuestCartTransferTest extends IntegrationTestCase
{
    private const int SOURCE_SESSION = 4_000_000_001;
    private const int SOURCE_EPOCH = 0;
    private const int NEXT_EPOCH = 1;
    private const int MAX_CART_QUANTITY = 9_007_199_254_740_991;
    private const int TRANSFERS_PER_PAGE = 25;
    private const string RECOVERY_RULE = 'guest_cart_sweep_recovery';
    private const string MERGE_EMAIL = 'guest-cart-merge@example.test';
    private const string MERGE_PASSWORD = 'guest cart proof password';
    private const string MERGE_LOGIN_KEY = 'guest-cart-merge-login';
    private const string MERGE_REQUEST = 'guest-cart-login-request';
    private const string REGISTRATION_SIBLING = 'guest-cart-registration-sibling';
    private const string REGISTRATION_OTHER_BROWSER = 'guest-cart-registration-other-browser';
    private const string OAUTH_TRIP_KEY = 'fedcba9876543210fedcba9876543210';
    private const string OAUTH_PROVIDER = 'oauth:github';
    private const string OAUTH_OFFLINE_TAB = 'guest-cart-merge-offline';
    private const string WAKE = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_WAKE;
    private const string OFFER = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_OFFER;
    private const string CLEARED = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_CLEARED;
    private const string RECEIVED = EcommerceShopSignalConstants::GUEST_CART_TRANSFER_RECEIVED;
    private const string MERGE_WAKE = EcommerceShopSignalConstants::SHOP_ACCOUNT_MERGE_CART_WAKE;

    /** @var list<int> People created by the case */
    private array $userIds = [];

    /** @var list<int> Sessions created by the case */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
    }

    protected function tearDown(): void
    {
        try {
            if (isset(Hilos::$rt->connections[self::MERGE_LOGIN_KEY])) {
                ExecutionContext::run(new ExecutionFrame(), static fn () =>
                    Hilos::$rt->connections[self::MERGE_LOGIN_KEY]->actions->unregister(),
                );
                RtTruthSourceRegistry::unregisterDaemon(EcommerceShopRtContext::connections);
            }
            foreach ($this->userIds as $userId) {
                Database::sqlRun('DELETE FROM hilos_account_operation WHERE loser_user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_user_merge WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_identity WHERE user_id = ?', [$userId]);
            }
            foreach ([self::SOURCE_SESSION, ...$this->sessionIds] as $sessionId) {
                Database::sqlRun('DELETE FROM shop_guest_cart_transfer WHERE session_id = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM session_product_cart WHERE session_id = ?', [$sessionId]);
                Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$sessionId]);
            }
            foreach ($this->sessionIds as $sessionId) {
                Database::sqlRun('DELETE FROM hilos_session WHERE id = ?', [$sessionId]);
            }
            foreach ($this->userIds as $userId) {
                Database::sqlRun('DELETE FROM user_product_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_account_deletion WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_access_log WHERE user_id = ?', [$userId]);
                Database::sqlRun('DELETE FROM hilos_user WHERE id = ?', [$userId]);
            }
            Hilos::$db->forgetLoadedDataForTest();
        } finally {
            Hilos::$sr = null;
            parent::tearDown();
        }
    }

    public function testBindAssignsOnlyTheDepartingGuestEpochAndLeavesRebindsAlone(): void
    {
        $first = $this->person('First cart recipient');
        $second = $this->person('Second cart recipient');
        $stock = self::product('pine-bark-5-l');
        $session = $this->guest();
        self::assertNotNull($session->id);
        Database::sqlRun(
            'INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, ?)',
            [$session->id, $stock, 3],
        );
        $impersonating = $this->guest();
        self::assertNotNull($impersonating->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 4, $impersonating->id);
        $empty = $this->guest();
        self::assertNotNull($empty->id);

        $this->underAgent($this->sessionsLibrary(), static function () use ($session, $impersonating, $empty, $first, $second): void {
            $session->actions->bindUser($first);
            $session->actions->bindUser($first);
            $session->actions->bindUser($second);
            $impersonating->actions->setImpersonator($first);
            $impersonating->actions->bindUser($second);
            $empty->actions->bindUser($first);
        });

        self::assertSame($second, $session->userId);
        self::assertSame(self::NEXT_EPOCH, $session->cartEpoch);
        $transferId = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($transferId);
        self::assertSame([$session->id, self::SOURCE_EPOCH, $first, false], Hilos::$db->shopGuestCartTransfers->storedTransfer($transferId));
        self::assertNull(self::savedAssignment($session->id, self::NEXT_EPOCH));
        self::assertSame(self::SOURCE_EPOCH, $impersonating->cartEpoch);
        self::assertNull(self::savedAssignment($impersonating->id, self::SOURCE_EPOCH));
        self::assertSame($first, $empty->userId);
        self::assertNull(self::savedAssignment($empty->id, self::SOURCE_EPOCH));
        self::assertNull(self::savedAssignment($empty->id, self::NEXT_EPOCH));
    }

    public function testRotationKeepsMintRefusalAndRollsBackWithTheOuterPrimary(): void
    {
        $userId = $this->person('Rotated cart recipient');
        $session = $this->guest();
        $occupied = $this->guest()->token;
        $oldToken = $session->token;
        $newToken = SessionToken::mint();
        self::assertNotNull($session->id);
        self::addSourceLine(self::SOURCE_EPOCH, self::product('pine-bark-5-l'), 3, $session->id);
        $stop = new RuntimeException('Rollback of the outer session transaction');
        $this->drainSignals();

        $this->underAgent($this->sessionsLibrary(), static function () use ($session, $userId, $occupied, $newToken, $oldToken, $stop): void {
            try {
                Database::transaction(DatabaseNames::PRIMARY, static function () use (
                    $session,
                    $userId,
                    $occupied,
                    $newToken,
                    $oldToken,
                    $stop,
                ): void {
                    try {
                        $session->actions->rotateTokenAndBindUser($occupied, $userId);
                        self::fail('The candidate belongs to a different session.');
                    } catch (DuplicateValueException) {
                        self::assertSame($oldToken, $session->token);
                        self::assertSame(self::SOURCE_EPOCH, $session->cartEpoch);
                        self::assertNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
                    }

                    $session->actions->rotateTokenAndBindUser($newToken, $userId);
                    self::assertSame($newToken, $session->token);
                    self::assertSame($userId, $session->userId);
                    self::assertSame(self::NEXT_EPOCH, $session->cartEpoch);
                    self::assertNotNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
                    self::assertNull(Hilos::$sr->getNextQueuedSignal());
                    throw $stop;
                });
                self::fail('The outer transaction should have rolled back.');
            } catch (RuntimeException $failure) {
                self::assertSame($stop, $failure);
            }

            self::assertSame($oldToken, $session->token);
            self::assertNull($session->userId);
            self::assertSame(self::SOURCE_EPOCH, $session->cartEpoch);
            self::assertSame(self::SOURCE_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($session->id, $oldToken));
            self::assertNull(Hilos::$db->sessions->findByToken($newToken));
            self::assertNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
            self::assertNull(Hilos::$sr->getNextQueuedSignal());

            $session->actions->rotateTokenAndBindUser($newToken, $userId);
            self::assertNull(Hilos::$db->sessions->findByToken($oldToken));
            self::assertSame(self::NEXT_EPOCH, Hilos::$db->sessions->findByToken($newToken)?->cartEpoch);
            self::assertNotNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
        });
    }

    public function testBindBeforeGiveWaitsForTheAssignedCartDuringGo(): void
    {
        $loser = $this->person('Cart merge loser');
        $survivor = $this->person('Cart merge survivor');
        $this->seedMergePassword($loser);
        $give = $this->readyGive($survivor, $loser);
        $session = $this->guest();
        $product = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 3, $session->id);
        $peer = self::mergePeer();
        try {
            $bound = self::withUserLockProbe($peer, $loser, HilosDbContext::sessions, fn () =>
                $this->underAgent($this->sessionsLibrary(), static fn () => $session->actions->bindUser($loser)),
            );
            $transfer = self::savedAssignment($session->id, self::SOURCE_EPOCH);
            self::assertNotNull($transfer);
            self::assertSame($loser, (int)$peer->execute_query(
                'SELECT user_id FROM hilos_session WHERE id = ?', [$session->id],
            )->fetch_column());
            self::assertSame(0, (int)$peer->execute_query(
                'SELECT received FROM shop_guest_cart_transfer WHERE id = ?', [$transfer],
            )->fetch_column());

            $login = $this->guest();
            $rotations = count(Hilos::$rt->hilosSessionRotations);
            RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
            try {
                $answer = $this->deliverLoginGrant($this->passwordGrant($login, $loser));
                self::assertSame($loser, $answer->userId);
                self::assertNotNull($answer->rotationTicket);
                self::assertSame($rotations + 1, count(Hilos::$rt->hilosSessionRotations));
                $this->underAgent($this->sessionsLibrary(), static fn () =>
                    Hilos::$rt->hilosSessionRotations->actions->forget($answer->rotationTicket),
                );
            } finally {
                RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            }

            self::assertSame(AccountOperationAnswerKind::Waits, $this->deliverMergeStep($give));
            self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
            self::assertFalse(Hilos::$db->userMerges->isMergedInDatabase($loser));
            self::assertSame($loser, (int)$peer->execute_query(
                'SELECT user_id FROM hilos_identity WHERE identifier = ?', [self::MERGE_EMAIL],
            )->fetch_column());
            self::assertSame([], self::accountLines($loser));
            self::assertUserLockHeld($bound);

            $accepted = self::withUserLockProbe($peer, $loser, EcommerceShopDbContext::shopGuestCartTransfers, fn () =>
                $this->deliver($loser, self::OFFER, new GuestCartTransferOfferSignalData($transfer, $loser)),
            );
            self::assertUserLockHeld($accepted);
            self::assertSame([$product => 3], self::accountLines($loser));
            self::assertSame(1, (int)$peer->execute_query(
                'SELECT received FROM shop_guest_cart_transfer WHERE id = ?', [$transfer],
            )->fetch_column());
            self::assertSame(AccountOperationAnswerKind::Done, $this->deliverMergeStep($give));
            self::assertSame([$product => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
            self::assertTrue(Hilos::$db->userMerges->isMergedInDatabase($loser));
        } finally {
            $peer->close();
        }
    }

    public function testGiveBeforeAProvenLoginLeavesTheGuestSessionAndCartIntact(): void
    {
        $loser = $this->person('Late proof loser');
        $survivor = $this->person('Late proof survivor');
        $this->seedMergePassword($loser);
        $session = $this->guest();
        $token = $session->token;
        $product = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 4, $session->id);
        $give = $this->readyGive($survivor, $loser);
        $grant = $this->passwordGrant($session, $loser);
        $peer = self::mergePeer();
        try {
            self::assertFalse(Hilos::$db->users[$loser]->block);
            self::giveInPeerProcess($give);
            self::assertSame($survivor, (int)$peer->execute_query(
                'SELECT survivor_user_id FROM hilos_user_merge WHERE user_id = ?', [$loser],
            )->fetch_column());
            self::assertFalse(Hilos::$db->users[$loser]->block, 'The sessions holder has yet to consume the Give sync.');
            $rotations = count(Hilos::$rt->hilosSessionRotations);
            RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
            try {
                $answer = $this->deliverLoginGrant($grant, refused: true);
                self::assertSame(AuthFlowOutcome::rejectTo(
                    AuthFlowOutcome::CODE_ACCOUNT_MERGING,
                    AuthFlowStep::IDENTIFIER,
                    AuthFlowIntent::LOGIN,
                    AccountMergeAccess::REFUSED_MESSAGE,
                )->toArray(), $answer->outcome);
                self::assertSame([$token, null, self::SOURCE_EPOCH], $peer->execute_query(
                    'SELECT token, user_id, cart_epoch FROM hilos_session WHERE id = ?', [$session->id],
                )->fetch_row());
                self::assertNull($answer->userId);
                self::assertNull($answer->rotationTicket);
                self::assertSame($rotations, count(Hilos::$rt->hilosSessionRotations));
                self::assertSame(0, (int)$peer->execute_query(
                    'SELECT COUNT(*) FROM shop_guest_cart_transfer WHERE session_id = ?', [$session->id],
                )->fetch_column());
                self::assertSame(4, (int)$peer->execute_query(
                    'SELECT quantity FROM session_product_cart WHERE session_id = ? AND product_id = ? AND cart_epoch = ?',
                    [$session->id, $product, self::SOURCE_EPOCH],
                )->fetch_column());
            } finally {
                RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            }
        } finally {
            $peer->close();
        }
    }

    #[DataProvider('browserProofs')]
    public function testRefusedSessionWriteAnswersTheBrowserProof(string $action): void
    {
        $userId = $this->person('Browser proof account');
        $session = $this->guest();
        $this->registerLoginTab($session);

        [$name, $payload] = match ($action) {
            HilosSignalConstants::HILOS_LOGIN => [
                HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
                new AuthSessionGrantSignalData($session->token, $userId, self::MERGE_LOGIN_KEY, self::MERGE_REQUEST, $action),
            ],
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => [
                HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED,
                new AuthPasswordChangedSignalData($userId, $session->token, self::MERGE_LOGIN_KEY, self::MERGE_EMAIL, self::MERGE_REQUEST, $action),
            ],
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => [
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED,
                new AuthRegistrationLandedSignalData(
                    self::MERGE_EMAIL, $userId, $session->token, self::MERGE_LOGIN_KEY,
                    requestId: self::MERGE_REQUEST, action: $action,
                ),
            ],
        };

        $frames = $this->withRefusedSessionWrite($session, fn () => $this->deliverToSessions($name, $payload));
        self::assertCount(1, $frames);
        $answer = $frames[0];
        self::assertInstanceOf(SessionStateSignalData::class, $answer);
        self::assertSame([self::MERGE_LOGIN_KEY], $answer->acceptKeys);
        self::assertSame(self::MERGE_REQUEST, $answer->requestId);
        self::assertSame($action, $answer->action);
        self::assertSame(AuthFlowOutcome::CODE_ACCOUNT_MERGING, $answer->outcome['code'] ?? null);
    }

    /** @return iterable<string, array{string}> Browser actions that complete a proof */
    public static function browserProofs(): iterable
    {
        yield 'sign-in' => [HilosSignalConstants::HILOS_LOGIN];
        yield 'password recovery' => [HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET];
        yield 'registration' => [HilosSignalConstants::HILOS_COMPLETE_REGISTRATION];
    }

    public function testRefusedSessionWriteSettlesRegistrationWaiters(): void
    {
        $userId = $this->person('Registration account');
        $session = $this->guest();
        $other = $this->guest();
        $holder = $this->sessionsLibrary();
        $this->registerLoginTab($session);
        $this->underAgent($holder, static function () use ($session, $other): void {
            $session->actions->holdPendingRegistration(self::MERGE_EMAIL);
            $waiters = Hilos::$rt->hilosRegistrationWaiters;
            $waiters->actions->park(self::REGISTRATION_SIBLING, self::MERGE_EMAIL, $session->token);
            $waiters->actions->park(self::REGISTRATION_OTHER_BROWSER, self::MERGE_EMAIL, $other->token);
        });
        try {
            $frames = $this->withRefusedSessionWrite($session, fn () => $this->deliverToSessions(
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED,
                new AuthRegistrationLandedSignalData(
                    self::MERGE_EMAIL,
                    $userId,
                    $session->token,
                    self::MERGE_LOGIN_KEY,
                    requestId: self::MERGE_REQUEST,
                    action: HilosSignalConstants::HILOS_COMPLETE_REGISTRATION,
                ),
            ));
            $notices = [];
            foreach ($frames as $frame) {
                if ($frame instanceof AuthConvergeSignalData) {
                    self::assertSame(AuthFlowStep::IDENTIFIER, $frame->step);
                    self::assertSame(AuthFlowIntent::LOGIN, $frame->intent);
                    $notices[$frame->acceptKey] = $frame->code;
                }
            }
            self::assertEquals([
                self::REGISTRATION_SIBLING => AuthFlowOutcome::CODE_ACCOUNT_MERGING,
                self::REGISTRATION_OTHER_BROWSER => AuthFlowOutcome::CODE_IDENTIFIER_TAKEN,
            ], $notices);
            self::assertNull(Hilos::$db->sessions[$session->id]->pendingRegistrationIdentifier);
            self::assertNull(Hilos::$rt->hilosRegistrationWaiters[self::REGISTRATION_SIBLING]);
            self::assertNull(Hilos::$rt->hilosRegistrationWaiters[self::REGISTRATION_OTHER_BROWSER]);
        } finally {
            $this->underAgent($holder, static function (): void {
                Hilos::$rt->hilosRegistrationWaiters->actions->release(self::REGISTRATION_SIBLING);
                Hilos::$rt->hilosRegistrationWaiters->actions->release(self::REGISTRATION_OTHER_BROWSER);
            });
        }
    }

    #[DataProvider('oauthTripKinds')]
    public function testRefusedSessionWriteAnswersOAuth(bool $hasTrip, bool $held): void
    {
        $userId = $this->person('OAuth proof account');
        $session = $this->guest();
        $this->registerLoginTab($session);
        $hash = HilosOAuthTrip::hashKey(self::OAUTH_TRIP_KEY);
        $grant = new AuthSessionGrantSignalData($session->token, $userId, self::MERGE_LOGIN_KEY, tripKeyHash: $hash);
        RtTruthSourceRegistry::registerDaemon(HilosOAuthTrip::RT_COLLECTION);
        try {
            if ($hasTrip) {
                $this->deliverToSessions(HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED, new OAuthTripOpenedSignalData(
                    $hash,
                    ProtectedModeRuntime::hashSessionToken($session->token),
                    $held ? self::OAUTH_OFFLINE_TAB : self::MERGE_LOGIN_KEY,
                    OAuthPendingLogin::MODE_LOGIN,
                    self::OAUTH_PROVIDER,
                ));
            }
            if ($held) {
                $this->deliverToSessions(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, $grant);
                self::assertSame(HilosOAuthTrip::ENDING_GRANTED, Hilos::$rt->hilosOAuthTrips[$hash]?->ending);
            }

            $frames = $this->withRefusedSessionWrite($session, function () use ($grant, $held): void {
                if (!$held) {
                    $this->deliverToSessions(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, $grant);

                    return;
                }

                $holder = $this->sessionsLibrary();
                $reply = $this->underAgent($holder, static fn () => $holder->onAgentAction(
                    self::MERGE_LOGIN_KEY,
                    HilosSignalConstants::HILOS_OAUTH_RESUME,
                    new OAuthResumeActionDTO(self::OAUTH_TRIP_KEY),
                ));
                self::assertInstanceOf(OAuthResumeReplyDTO::class, $reply);
                self::assertTrue($reply->known);
            });
            if (!$hasTrip) {
                self::assertNotEmpty($frames);
                $answer = $frames[array_key_last($frames)];
                self::assertInstanceOf(SessionStateSignalData::class, $answer);
                self::assertSame([self::MERGE_LOGIN_KEY], $answer->acceptKeys);
                self::assertSame(AuthFlowOutcome::CODE_ACCOUNT_MERGING, $answer->outcome['code'] ?? null);

                return;
            }

            $results = array_values(array_filter($frames, static fn ($frame): bool => $frame instanceof OAuthResultSignalData));
            self::assertCount(1, $results);
            self::assertSame(self::MERGE_LOGIN_KEY, $results[0]->acceptKey);
            self::assertSame(OAuthResultSignalData::REASON_ACCOUNT_MERGING, $results[0]->reason);
            self::assertSame(
                $held ? HilosOAuthTrip::ENDING_GRANTED : OAuthResultSignalData::REASON_ACCOUNT_MERGING,
                Hilos::$rt->hilosOAuthTrips[$hash]?->ending,
            );
        } finally {
            $this->underAgent($this->sessionsLibrary(), static function () use ($hash): void {
                Hilos::$rt->hilosOAuthTrips[$hash]?->actions->end(OAuthResultSignalData::REASON_LOGIN_FAILED);
                Hilos::$rt->hilosOAuthTrips->actions->forgetEnded(-1);
            });
            RtTruthSourceRegistry::unregisterDaemon(HilosOAuthTrip::RT_COLLECTION);
        }
    }

    /** @return iterable<string, array{bool, bool}> Provider grants with and without an originating tab */
    public static function oauthTripKinds(): iterable
    {
        yield 'no trip' => [false, false];
        yield 'live trip' => [true, false];
        yield 'held trip' => [true, true];
    }

    #[DataProvider('unblockEntryPoints')]
    public function testRefusedSessionWriteLeavesAnUnblockedBrowserAnonymous(bool $handshake): void
    {
        $userId = $this->person('Unblocked account');
        $session = $this->guest();
        $holder = $this->sessionsLibrary();
        $this->underAgent($holder, static fn () => $session->actions->holdBlockedNotice($userId, true));
        if (!$handshake) {
            $this->registerLoginTab($session);
        }

        $frames = $this->withRefusedSessionWrite($session, function () use ($session, $holder, $userId, $handshake): void {
            if (!$handshake) {
                $this->deliverToSessions(HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED, new AccountBlockChangedSignalData($userId));

                return;
            }

            $this->underAgent($holder, static fn () => $holder->onSignalHandshake(new WebSocketHandshakeSignalDTO(
                [], self::MERGE_LOGIN_KEY, [SessionCookieName::resolve() => $session->token], null, sessionToken: $session->token,
            ), '', ''));
        });
        $states = array_values(array_filter($frames, static fn ($frame): bool => $frame instanceof SessionStateSignalData));
        self::assertCount(1, $states);
        self::assertSame([self::MERGE_LOGIN_KEY], $states[0]->acceptKeys);
        self::assertNull($states[0]->accountBlocked);
        self::assertNull(Hilos::$db->sessions[$session->id]->blockedUserId);
    }

    /** @return iterable<string, array{bool}> Live and returning browsers */
    public static function unblockEntryPoints(): iterable
    {
        yield 'live tab' => [false];
        yield 'handshake' => [true];
    }

    #[DataProvider('serviceBindEntryPoints')]
    public function testRefusedSessionWriteAnswersTheWaitingCommand(string $route): void
    {
        $userId = $this->person('Service bind account');
        $session = $this->guest();
        $holder = $this->sessionsLibrary();

        if ($route === HilosSignalConstants::HILOS_USER_ADMIN_CREATE_DONE) {
            $request = new CommandRequestDTO(self::MERGE_REQUEST, CliCommands::ADMIN_CREATE, [
                AdminCommandConstants::FIELD_SESSION_TOKEN => $session->token,
            ]);
            $this->underAgent($holder, static fn () => $holder->onSignalCommand($request, '', ''));
        }

        $frames = $this->withRefusedSessionWrite($session, function () use ($session, $userId, $route, $holder): void {
            if ($route === CliCommands::SESSION_TEST_BIND) {
                $request = new CommandRequestDTO(self::MERGE_REQUEST, CliCommands::SESSION_TEST_BIND, [
                    TestUserCommandConstants::FIELD_SESSION_TOKEN => $session->token,
                    TestUserCommandConstants::FIELD_USER_ID => $userId,
                ]);
                $this->underAgent($holder, static fn () => $holder->onSignalCommand($request, '', ''));

                return;
            }

            $payload = match ($route) {
                HilosSignalConstants::HILOS_USER_ADMIN_CREATE_DONE => UserAdminCreateDoneSignalData::created(
                    new UserAdminCreateSignalData(self::MERGE_REQUEST, $session->token, false), $userId,
                ),
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => UserAdminCommandDoneSignalData::to(
                    new UserAdminCommandSignalData(
                        $userId, true, $route, self::MERGE_REQUEST, CliCommands::ADMIN_CREATE, $session->token, false,
                    ),
                    null,
                ),
                HilosSignalConstants::HILOS_SESSION_REBIND => new SessionRebindSignalData(
                    $session->token, $userId, correlationId: self::MERGE_REQUEST,
                ),
            };
            $this->deliverToSessions($route, $payload);
        });
        self::assertCount(1, $frames);
        $reply = $frames[0];
        self::assertInstanceOf(CommandReplyDTO::class, $reply);
        self::assertSame(self::MERGE_REQUEST, $reply->correlationId);
        self::assertFalse($reply->isOk());
        self::assertSame((new AccountMergedSignInException())->getMessage(), $reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /** @return iterable<string, array{string}> Command routes that perform the plain bind */
    public static function serviceBindEntryPoints(): iterable
    {
        yield 'rebind signal' => [HilosSignalConstants::HILOS_SESSION_REBIND];
        yield 'fixture command' => [CliCommands::SESSION_TEST_BIND];
        yield 'administrator creation' => [HilosSignalConstants::HILOS_USER_ADMIN_CREATE_DONE];
        yield 'administrator command' => [HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE];
    }

    public function testRefusedSessionWriteAnswersARebindRequestedByATab(): void
    {
        $userId = $this->person('Rebind account');
        $session = $this->guest();
        $this->registerLoginTab($session);
        $frames = $this->withRefusedSessionWrite($session, fn () => $this->deliverToSessions(
            HilosSignalConstants::HILOS_SESSION_REBIND,
            new SessionRebindSignalData($session->token, $userId, initiatorAcceptKey: self::MERGE_LOGIN_KEY),
        ));
        self::assertNotEmpty($frames);
        $answer = $frames[array_key_last($frames)];
        self::assertInstanceOf(SessionStateSignalData::class, $answer);
        self::assertSame([self::MERGE_LOGIN_KEY], $answer->acceptKeys);
        self::assertSame(AuthFlowOutcome::CODE_ACCOUNT_MERGING, $answer->outcome['code'] ?? null);
    }

    public function testPlainBindSeesGiveInAnEarlierPrimarySnapshot(): void
    {
        $loser = $this->person('Earlier snapshot loser');
        $survivor = $this->person('Earlier snapshot survivor');
        $give = $this->readyGive($survivor, $loser);
        $session = $this->guest();
        $peer = self::mergePeer();
        try {
            self::assertFalse(Hilos::$db->users[$loser]->block);
            $snapshotRefusal = Database::readSnapshot(DatabaseNames::PRIMARY, function () use ($give, $loser, $survivor, $session, $peer): ?string {
                $tombstone = static fn () => Database::sql(
                    'SELECT survivor_user_id FROM hilos_user_merge WHERE user_id = ?', [$loser],
                )->field('survivor_user_id');
                self::assertNull($tombstone());
                self::giveInPeerProcess($give);
                self::assertSame($survivor, (int)$peer->execute_query(
                    'SELECT survivor_user_id FROM hilos_user_merge WHERE user_id = ?', [$loser],
                )->fetch_column());
                self::assertNull($tombstone(), 'The consistent snapshot predates the committed Give.');
                try {
                    $this->underAgent($this->sessionsLibrary(), static fn () => $session->actions->bindUser($loser));
                } catch (ValidationException $refusal) {
                    return $refusal->getMessage();
                }

                self::fail('The bind accepted a folded account inside the earlier snapshot.');
            });
            self::assertSame('This account was merged into another one', $snapshotRefusal);
            self::assertFalse(Hilos::$db->users[$loser]->block, 'The sessions holder has yet to consume the Give sync.');
        } finally {
            $peer->close();
        }
    }

    public function testSessionStateWithoutTabsWakesItsRecipientAndRecoveryRepairsLostReplies(): void
    {
        $userId = $this->person('Recipient of the saved cart');
        $otherId = $this->person('Other account');
        $stock = self::product('pine-bark-5-l');
        $session = $this->guest();
        self::assertNotNull($session->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3, $session->id);
        $this->underAgent($this->sessionsLibrary(), static function () use ($session, $userId): void {
            $session->actions->bindUser($userId);
            $session->actions->unbindUser();
        });
        self::addSourceLine(self::NEXT_EPOCH, $stock, 2, $session->id);
        $transferId = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($transferId);

        $carts = $this->startGuestCarts();
        $this->drainSignals();
        $this->sendSessionStateWithoutTabs($session);
        $wake = $this->queuedTransferFrame(self::WAKE);
        self::assertSame(['sessionId' => $session->id], $wake->data->data->toArray());
        $this->refuseSourceFrame(
            $carts,
            self::WAKE,
            new GuestCartTransferWakeSignalData($session->id),
            $this->personAgent($userId)->getAgentSignalSource(),
        );
        $this->dispatchSourceFrame($carts, $wake);
        $offer = $this->queuedTransferFrame(self::OFFER);
        self::assertEquals(new GuestCartTransferOfferSignalData($transferId, $userId), $offer->data->data);

        $this->deliver($userId, self::OFFER, $offer->data->data);
        $received = $this->queuedTransferFrame(self::RECEIVED);
        self::assertInstanceOf(GuestCartTransferReceivedSignalData::class, $received->data->data);
        $this->refuseSourceFrame($carts, self::RECEIVED, $received->data->data, $this->personAgent($otherId)->getAgentSignalSource());
        self::assertSame([$stock => 3], self::accountLines($userId));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame([$stock => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));

        // Both receipts are lost after the account transaction commits.
        $this->drainSignals();
        $this->deliver($userId, self::OFFER, $offer->data->data);
        self::assertSame([[self::RECEIVED, $received->data->data->toArray()]], $this->transferFrames($userId));
        self::assertSame([$stock => 3], self::accountLines($userId));

        $this->runRecoveryTick($carts);
        $cleared = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals(new GuestCartTransferClearedSignalData($transferId, $userId), $cleared->data->data);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$stock => 2], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));

        $this->drainSignals();
        $this->runRecoveryTick($carts);
        $replayed = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals($cleared->data->data, $replayed->data->data);
        $this->deliver($userId, self::CLEARED, $replayed->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertNull(self::savedAssignment($session->id, self::SOURCE_EPOCH));
        self::assertSame([$stock => 2], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));

        $this->drainSignals();
        $this->deliver($userId, self::OFFER, $offer->data->data);
        self::assertSame([], $this->transferFrames($userId));
        self::assertSame([$stock => 3], self::accountLines($userId));
    }

    public function testRecoveryAfterLostWakesAndSessionSweepUsesBothSavedRecipients(): void
    {
        $first = $this->person('First recipient after sweep');
        $second = $this->person('Second recipient after sweep');
        $stock = self::product('pine-bark-5-l');
        $firstVoucher = self::voucherId('SPRING10');
        $secondVoucher = self::voucherId('WEDDING15');
        $session = $this->guest();
        self::assertNotNull($session->id);
        $carts = $this->startGuestCarts();
        $library = $this->sessionsLibrary();
        self::addAccountLine($first, $stock, 2);
        self::addAccountLine($second, $stock, 5);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3, $session->id);
        $this->underAgent($carts, static fn () => Hilos::$db->sessionVoucherCarts->actions->create(
            $session->id, self::SOURCE_EPOCH, $firstVoucher,
        ));

        $this->underAgent($library, static fn () => $session->actions->bindUser($first));
        $firstTransfer = self::savedAssignment($session->id, self::SOURCE_EPOCH);
        self::assertNotNull($firstTransfer);
        $this->sendSessionStateWithoutTabs($session);
        $this->queuedTransferFrame(self::WAKE);
        $this->underAgent($library, static fn () => $session->actions->unbindUser());
        self::assertSame(self::NEXT_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($session->id, $session->token));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));

        self::addSourceLine(self::NEXT_EPOCH, $stock, 4, $session->id);
        $this->underAgent($carts, static fn () => Hilos::$db->sessionVoucherCarts->actions->create(
            $session->id, self::NEXT_EPOCH, $secondVoucher,
        ));
        $this->underAgent($library, static fn () => $session->actions->bindUser($second));
        $secondTransfer = self::savedAssignment($session->id, self::NEXT_EPOCH);
        self::assertNotNull($secondTransfer);
        $this->sendSessionStateWithoutTabs($session);
        $this->queuedTransferFrame(self::WAKE);
        $this->underAgent($library, static fn () => $session->actions->expire());

        $live = $this->guest();
        self::assertNotNull($live->id);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 7, $live->id);
        $this->underAgent($carts, static fn () => Hilos::$db->sessionVoucherCarts->actions->create(
            $live->id, self::SOURCE_EPOCH, $secondVoucher,
        ));
        $this->drainSignals();
        self::assertNotNull($library->armCronRuleForTest(CronRule::FRAMEWORK_NAME_PREFIX . 'sessions_expire'));
        $this->underAgent($library, $library->onTick(...));
        $swept = $this->queuedTransferFrame(HilosSignalConstants::HILOS_SESSIONS_SWEPT);
        self::assertEquals(new SessionsSweptSignalData([$session->token], [$session->id]), $swept->data->data);
        $this->dispatchSourceFrame($carts, $swept);

        self::assertNull(Hilos::$db->sessions->findByToken($session->token));
        self::assertSame([$session->id, self::SOURCE_EPOCH, $first, false], Hilos::$db->shopGuestCartTransfers->storedTransfer($firstTransfer));
        self::assertSame([$session->id, self::NEXT_EPOCH, $second, false], Hilos::$db->shopGuestCartTransfers->storedTransfer($secondTransfer));
        self::assertSame([$stock => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertSame($firstVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::SOURCE_EPOCH));
        self::assertSame($secondVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));
        self::assertSame([$stock => 2], self::accountLines($first));
        self::assertSame([$stock => 5], self::accountLines($second));

        $this->runRecoveryTick($carts);
        $firstOffer = $this->queuedTransferFrame(self::OFFER);
        $secondOffer = $this->queuedTransferFrame(self::OFFER);
        self::assertEquals(new GuestCartTransferOfferSignalData($firstTransfer, $first), $firstOffer->data->data);
        self::assertEquals(new GuestCartTransferOfferSignalData($secondTransfer, $second), $secondOffer->data->data);
        $this->deliver($first, self::OFFER, $firstOffer->data->data);
        $this->queuedTransferFrame(self::RECEIVED);
        $this->dispatchSourceFrame($carts, $swept);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($firstTransfer, $first));
        self::assertSame([$stock => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame($firstVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::SOURCE_EPOCH));

        $this->deliver($first, self::OFFER, $firstOffer->data->data);
        $this->dispatchSourceFrame($carts, $this->queuedTransferFrame(self::RECEIVED));
        $this->deliver($first, self::CLEARED, $this->queuedTransferFrame(self::CLEARED)->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($firstTransfer));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::SOURCE_EPOCH));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($secondTransfer, $second));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertSame($secondVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));

        $this->deliver($second, self::OFFER, $secondOffer->data->data);
        $this->dispatchSourceFrame($carts, $this->queuedTransferFrame(self::RECEIVED));
        $this->deliver($second, self::CLEARED, $this->queuedTransferFrame(self::CLEARED)->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($secondTransfer));

        self::assertSame([$stock => 5], self::accountLines($first));
        self::assertSame([$stock => 9], self::accountLines($second));
        self::assertSame($firstVoucher, self::accountVoucherId($first));
        self::assertSame($secondVoucher, self::accountVoucherId($second));
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::NEXT_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId($session->id, self::NEXT_EPOCH));
        self::assertSame(self::SOURCE_EPOCH, Hilos::$db->sessions->activeGuestCartEpoch($live->id, $live->token));
        self::assertSame([$stock => 7], Hilos::$db->sessionProductCarts->storedCartQuantities($live->id, self::SOURCE_EPOCH));
        self::assertSame($secondVoucher, Hilos::$db->sessionVoucherCarts->storedVoucherId($live->id, self::SOURCE_EPOCH));
    }

    public function testOffersOnlyTheSavedAddressForSmallAndLargeCarts(): void
    {
        $userId = $this->person('Recipient of two cart sizes');
        $products = self::firstProducts(26);
        self::addSourceLine(self::SOURCE_EPOCH, $products[0], 1);
        foreach ($products as $productId) {
            self::addSourceLine(self::NEXT_EPOCH, $productId, 1);
        }
        $small = $this->assign($userId);
        $large = $this->assign($userId, self::NEXT_EPOCH);
        $carts = $this->startGuestCarts();
        $this->drainSignals();

        $this->runRecoveryTick($carts);

        foreach ([$small, $large] as $transferId) {
            $offer = $this->queuedTransferFrame(self::OFFER);
            self::assertSame(['transferId' => $transferId, 'userId' => $userId], $offer->data->data->toArray());
            $recipient = $this->personAgent($userId);
            self::assertEquals([new AgentDestination($recipient->getType(), $recipient->getIndex())], Hilos::$sr->getDestinations($offer));
        }
    }

    public function testRecoveryReachesTheNextTransferPageAfterAnEmptyAssignmentAndOwnerRestart(): void
    {
        $userId = $this->person('Recipient beyond the recovery page');
        $stock = self::product('pine-bark-5-l');
        $waiting = $this->assign($userId);
        $firstPage = [$waiting];
        for ($epoch = 1; $epoch < self::TRANSFERS_PER_PAGE; $epoch++) {
            self::addSourceLine($epoch, $stock, 1);
            $firstPage[] = $this->assign($userId, $epoch);
        }
        self::addSourceLine(self::TRANSFERS_PER_PAGE, $stock, 1);
        $last = $this->assign($userId, self::TRANSFERS_PER_PAGE);

        $carts = $this->startGuestCarts();
        try {
            $this->drainSignals();
            $this->runRecoveryTick($carts);
            self::assertSame($firstPage, $this->queuedOfferIds());
        } finally {
            $this->underAgent($carts, $carts->onStop(...));
            TruthSourceRegistry::unregisterAgent($carts->getId());
        }

        $carts = $this->startGuestCarts();
        $this->runRecoveryTick($carts);
        self::assertSame($firstPage, $this->queuedOfferIds());
        $this->runRecoveryTick($carts);
        self::assertSame([$last], $this->queuedOfferIds());
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($waiting, $userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($last, $userId));
    }

    public function testRecoverySharesItsRowBudgetAcrossReceivedPairsAndReplaysClearance(): void
    {
        $first = $this->person('First received cart');
        $second = $this->person('Second received cart');
        $third = $this->person('Pending cart behind the received page');
        $pendingEpoch = self::NEXT_EPOCH + 1;
        $session = $this->guest();
        self::assertNotNull($session->id);
        $products = self::firstProducts(25);
        foreach (array_slice($products, 0, 10) as $productId) {
            self::addSourceLine(self::SOURCE_EPOCH, $productId, 1);
        }
        foreach ($products as $productId) {
            self::addSourceLine(self::NEXT_EPOCH, $productId, 1);
        }
        $voucherId = (int)Database::sql('SELECT id FROM voucher WHERE code = ?', ['SPRING10'])->field('id');
        Database::sqlRun(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) VALUES (?, ?, ?), (?, ?, ?)',
            [self::SOURCE_SESSION, self::SOURCE_EPOCH, $voucherId, self::SOURCE_SESSION, self::NEXT_EPOCH, $voucherId],
        );
        self::addSourceLine(self::SOURCE_EPOCH, $products[0], 4, $session->id);
        self::addAccountLine($first, $products[0], 5);
        self::addAccountLine($second, $products[0], 9);
        $firstTransfer = $this->assign($first);
        $secondTransfer = $this->assign($second, self::NEXT_EPOCH);
        self::addSourceLine($pendingEpoch, $products[0], 1);
        $pendingTransfer = $this->assign($third, $pendingEpoch);
        Database::sqlRun('UPDATE shop_guest_cart_transfer SET received = 1 WHERE id IN (?, ?)', [$firstTransfer, $secondTransfer]);
        $carts = $this->startGuestCarts();
        $this->drainSignals();

        $this->runRecoveryTick($carts);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertCount(11, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertSame($voucherId, Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::NEXT_EPOCH));
        $firstClear = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals(new GuestCartTransferClearedSignalData($firstTransfer, $first), $firstClear->data->data);
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(self::CLEARED, $signal->signalName->getName());
        }
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($secondTransfer, $second));
        self::assertSame([$products[0] => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$products[0] => 5], self::accountLines($first));
        self::assertSame([$products[0] => 9], self::accountLines($second));
        $this->deliver($first, self::CLEARED, $firstClear->data->data);

        $this->runRecoveryTick($carts);
        self::assertCount(11, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertSame([$pendingTransfer], $this->queuedOfferIds());

        $this->runRecoveryTick($carts);
        self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertNull(Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::NEXT_EPOCH));
        $secondClear = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals(new GuestCartTransferClearedSignalData($secondTransfer, $second), $secondClear->data->data);

        // Reenter after the last deletion has committed without an observed clearance.
        $this->runRecoveryTick($carts);
        $replayedClear = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals($secondClear->data->data, $replayedClear->data->data);
        $this->deliver($second, self::CLEARED, $replayedClear->data->data);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedTransfer($secondTransfer));
        self::assertSame([$products[0] => 4], Hilos::$db->sessionProductCarts->storedCartQuantities($session->id, self::SOURCE_EPOCH));
        self::assertSame([$products[0] => 9], self::accountLines($second));
    }

    /** Empty and product-only receipts clear even while unrelated voucher objects await DB sync. */
    public function testRecoveryClearsVoucherlessReceiptsWithALargeHeldVoucherMirror(): void
    {
        $unrelated = $this->guest()->id;
        $voucherId = self::voucherId('SPRING10');
        $product = self::product('pine-bark-5-l');
        Database::sqlRun(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) SELECT ?, seq, ? FROM seq_1_to_512',
            [$unrelated, $voucherId],
        );
        $vouchers = Hilos::$db->sessionVoucherCarts;
        $heldCount = 0;
        foreach ($vouchers->whereColumnIs(SessionVoucherCart::sessionId, $unrelated) as $binding) {
            self::assertSame($voucherId, $binding->voucherId);
            ++$heldCount;
        }
        self::assertSame(512, $heldCount);
        Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ?', [$unrelated]);

        $userId = $this->person('Recipient of voucherless carts');
        $transfers = [];
        foreach ([0, 1, 2, 3] as $epoch) {
            $transfers[] = $this->assign($userId, $epoch);
        }
        foreach ([1, 3] as $epoch) {
            self::addSourceLine($epoch, $product, 1);
        }
        Database::sqlRun('UPDATE shop_guest_cart_transfer SET received = TRUE WHERE session_id = ?', [self::SOURCE_SESSION]);
        $carts = $this->startGuestCarts();
        $this->drainSignals();

        $this->runRecoveryTick($carts);
        foreach ($transfers as $epoch => $transferId) {
            self::assertEquals(
                new GuestCartTransferClearedSignalData($transferId, $userId),
                $this->queuedTransferFrame(self::CLEARED)->data->data,
            );
            self::assertSame([], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, $epoch));
        }
    }

    public function testAppliesOnceAndClearsOnlyWhenTheReceivedEpochIsEmpty(): void
    {
        $userId = $this->person('Transfer recipient');
        $stock = self::product('pine-bark-5-l');
        $unique = self::product('morning-meadow');
        self::addAccountLine($userId, $stock, self::MAX_CART_QUANTITY - 2);
        self::addAccountLine($userId, $unique, 1);
        $this->personAgent($userId);
        $stockLineId = (int)Database::sql('SELECT id FROM user_product_cart WHERE user_id = ? AND product_id = ?', [$userId, $stock])->field('id');
        $heldStock = Hilos::$db->userProductCarts[$stockLineId]->getObject();
        Database::sqlRun('UPDATE user_product_cart SET quantity = ? WHERE id = ?', [self::MAX_CART_QUANTITY - 1, $stockLineId]);
        self::assertSame(self::MAX_CART_QUANTITY - 2, $heldStock->quantity);
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 2);
        self::addSourceLine(self::SOURCE_EPOCH, $unique, 1);
        self::addSourceLine(self::NEXT_EPOCH, $stock, 4);
        $voucherId = self::voucherId('SPRING10');
        self::addSourceVoucher(self::SOURCE_EPOCH, $voucherId);
        Hilos::$db->sessionProductCarts->objectCollection->preloadAll();
        Hilos::$db->shopGuestCartTransfers->objectCollection->preloadAll();
        Database::sqlRun(
            'UPDATE session_product_cart SET quantity = 1 WHERE session_id = ? AND cart_epoch = ? AND product_id = ?',
            [self::SOURCE_SESSION, self::SOURCE_EPOCH, $stock],
        );
        Database::sqlRun(
            'INSERT INTO shop_guest_cart_transfer (session_id, cart_epoch, user_id) VALUES (?, ?, ?)',
            [self::SOURCE_SESSION, self::SOURCE_EPOCH, $userId],
        );
        $transferId = self::savedAssignment(self::SOURCE_SESSION, self::SOURCE_EPOCH);
        self::assertNotNull($transferId);
        self::assertFalse(isset(Hilos::$db->shopGuestCartTransfers[$transferId]));
        $heldSource = Hilos::$db->sessionProductCarts->forCart(self::SOURCE_SESSION, self::SOURCE_EPOCH)
            ->filter(static fn (SessionProductCart $line): bool => $line->productId === $stock)->first();
        self::assertSame(2, $heldSource?->quantity);

        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $clear = new GuestCartTransferClearedSignalData($transferId, $userId);
        $this->drainSignals();

        $this->deliver($userId, self::OFFER, $offer);

        $receipt = ['transferId' => $transferId, 'userId' => $userId];
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId,
                'lines' => [['productId' => $stock, 'quantity' => self::MAX_CART_QUANTITY], ['productId' => $unique, 'quantity' => 1]],
                'voucherId' => $voucherId,
            ]],
            [self::RECEIVED, $receipt],
        ], $this->transferFrames($userId));
        self::assertSame([$stock => self::MAX_CART_QUANTITY, $unique => 1], self::accountLines($userId));
        self::assertSame($heldStock, Hilos::$db->userProductCarts[$stockLineId]->getObject());
        self::assertSame(self::MAX_CART_QUANTITY, $heldStock->quantity);
        self::assertLessThan(self::MAX_CART_QUANTITY, Hilos::$db->productWarehouseStocks->totalsOfProducts($stock)->of($stock)->unitCount);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));

        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([[self::RECEIVED, $receipt]], $this->transferFrames($userId));
        self::assertSame([$stock => self::MAX_CART_QUANTITY, $unique => 1], self::accountLines($userId));

        $this->deliver($userId, self::CLEARED, $clear);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::deleteSource();
        $this->deliver($userId, self::CLEARED, $clear);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        Database::sqlRun('DELETE FROM session_voucher_cart WHERE session_id = ? AND cart_epoch = ?', [self::SOURCE_SESSION, self::SOURCE_EPOCH]);
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([[self::RECEIVED, $receipt]], $this->transferFrames($userId));
        $otherId = $this->person('Different clearance recipient');
        $this->deliver($otherId, self::CLEARED, new GuestCartTransferClearedSignalData($transferId, $otherId));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        $this->deliver($userId, self::CLEARED, $clear);
        self::assertNull(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));

        $this->deliver($userId, self::CLEARED, $clear);
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([], $this->transferFrames($userId));
        self::assertSame([$stock => self::MAX_CART_QUANTITY, $unique => 1], self::accountLines($userId));
        self::assertSame($voucherId, self::accountVoucherId($userId));
    }

    public function testLateMergeCartOfferCannotRepeatTheReceiptAfterGive(): void
    {
        $loser = $this->person('Late cart offer loser');
        $survivor = $this->person('Late cart offer survivor');
        self::addSourceLine(self::SOURCE_EPOCH, self::product('pine-bark-5-l'), 3);
        $transferId = $this->assign($loser);
        $offer = new GuestCartTransferOfferSignalData($transferId, $loser);
        $give = $this->readyGive($survivor, $loser);
        self::assertSame(AccountOperationAnswerKind::Waits, $this->deliverMergeStep($give));
        $this->deliver($loser, self::OFFER, $offer);
        self::assertSame(AccountOperationAnswerKind::Done, $this->deliverMergeStep($give));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));

        try {
            $this->deliver($loser, self::OFFER, $offer);
            self::fail('A folded account repeated a guest cart receipt.');
        } catch (AgentException $failure) {
            self::assertInstanceOf(ValidationException::class, $failure->getPrevious());
        }
        self::assertSame([], $this->transferFrames($loser));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));
    }

    /** @param bool $deliverWake Whether the new frame is delivered or lost */
    #[DataProvider('mergeWakeDelivery')]
    public function testMergeWakeResumesGiveFromTheCommittedReceipt(bool $deliverWake): void
    {
        $loser = $this->person('Guest cart merge loser');
        $survivor = $this->person('Guest cart merge survivor');
        $product = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 3);
        $transferId = $this->assign($loser);
        $offer = new GuestCartTransferOfferSignalData($transferId, $loser);
        $give = $this->readyGive($survivor, $loser);
        self::assertSame(AccountOperationAnswerKind::Waits, $this->deliverMergeStep($give));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));

        $peer = self::mergePeer();
        $router = Hilos::$sr;
        $observed = new class ($peer, $transferId, $loser) extends SignalRouter {
            public int $wakes = 0;

            public function __construct(
                private readonly mysqli $peer,
                private readonly int $transferId,
                private readonly int $loser,
            ) {
                parent::__construct();
            }

            public function queueSignal(
                SignalSourceInterface $signalSource,
                SignalTypeInterface $signalType,
                SignalNameInterface $signalName,
                SignalDataInterface $signalData,
            ): void {
                if ($signalName->getName() === EcommerceShopSignalConstants::SHOP_ACCOUNT_MERGE_CART_WAKE) {
                    GuestCartTransferTest::assertNull(Database::runtimeTransactionLevel(DatabaseConnectionDefaults::PRIMARY_INDEX));
                    GuestCartTransferTest::assertSame(1, (int)$this->peer->execute_query(
                        'SELECT received FROM shop_guest_cart_transfer WHERE id = ?', [$this->transferId],
                    )->fetch_column());
                    $this->peer->begin_transaction();
                    try {
                        $this->peer->execute_query('SELECT id FROM hilos_user WHERE id = ? FOR UPDATE NOWAIT', [$this->loser])->free();
                    } finally {
                        $this->peer->rollback();
                    }
                    ++$this->wakes;
                }
                parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
            }
        };
        Hilos::initSignalRouter($observed);
        try {
            $this->deliver($loser, self::OFFER, $offer);
            $this->deliver($loser, self::OFFER, $offer);
            self::assertSame(1, $observed->wakes);
            $wake = $this->queuedTransferFrame(self::MERGE_WAKE);
            self::assertInstanceOf(ShopAccountMergeCartWakeSignalData::class, $wake->data->data);
            self::assertSame($give->operationId, $wake->data->data->operationId);
            self::assertSame(
                SignalSource::describe($this->personAgent($loser)->getAgentSignalSource()),
                SignalSource::describe($wake->signalSource),
            );
            self::assertSame([[self::RECEIVED, ['transferId' => $transferId, 'userId' => $loser]]], $this->transferFrames($loser));
        } finally {
            Hilos::initSignalRouter($router);
            $peer->close();
        }
        self::assertSame([$product => 3], self::accountLines($loser));

        if ($deliverWake) {
            foreach ([
                new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER, (string)$survivor),
                new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_USER),
                new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE, (string)$loser),
            ] as $sender) {
                $this->dispatchMergeFrame(new SignalDTO($sender, $wake->signalType, $wake->signalName, $wake->data));
                self::assertFalse(Hilos::$sr->hasQueuedSignals());
            }
            $this->dispatchMergeFrame(new SignalDTO(
                $wake->signalSource,
                $wake->signalType,
                $wake->signalName,
                new AgentSignalData(new ShopAccountMergeCartWakeSignalData(PHP_INT_MAX)),
            ));
            self::assertFalse(Hilos::$sr->hasQueuedSignals());
            $this->dispatchMergeFrame($wake);
        } else {
            $holder = $this->sessionsLibrary();
            self::assertNotNull($holder->armCronRuleForTest('hilos_account_erasure_recovery'));
            $this->underAgent($holder, $holder->onTick(...));
        }
        $ask = $this->queuedTransferFrame(HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP);
        self::assertInstanceOf(AccountOperationAskSignalData::class, $ask->data->data);
        self::assertSame($give->id, $ask->data->data->assignmentId);
        if ($deliverWake) {
            $this->dispatchMergeFrame($wake);
            $repeat = $this->queuedTransferFrame(HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP);
            self::assertSame($ask->data->data->toArray(), $repeat->data->data->toArray());
        }
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));

        $this->dispatchMergeFrame($ask);
        self::assertSame(AccountOperationAnswerKind::Done, self::readMergeAnswer($give));
        $this->drainSignals();
        $this->dispatchMergeFrame($wake);
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
        self::assertNotNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));
    }

    /** @return iterable<string, array{bool}> Whether the wake reaches the holder */
    public static function mergeWakeDelivery(): iterable
    {
        yield 'wake' => [true];
        yield 'recovery after loss' => [false];
    }

    public function testMergeWakeIgnoresCancellationBeforeGive(): void
    {
        $loser = $this->person('Canceled cart merge loser');
        $survivor = $this->person('Canceled cart merge survivor');
        self::addSourceLine(self::SOURCE_EPOCH, self::product('pine-bark-5-l'), 3);
        $transferId = $this->assign($loser);
        $give = $this->readyGive($survivor, $loser);
        self::assertSame(AccountOperationAnswerKind::Waits, $this->deliverMergeStep($give));
        $this->deliver($loser, self::OFFER, new GuestCartTransferOfferSignalData($transferId, $loser));
        $wake = $this->queuedTransferFrame(self::MERGE_WAKE);
        $this->drainSignals();

        $state = $this->underAgent($this->sessionsLibrary(), static fn () => AccountMergeCoordinator::cancel($give->operation));
        self::assertSame(AccountMergeCancellationState::WaitingOwners, $state);
        $this->drainSignals();
        $this->dispatchMergeFrame($wake);
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
        self::assertSame(AccountOperationAnswerKind::Refused, $this->deliverMergeStep($give));
        self::assertNull(Hilos::$db->accountOperationResults->byAssignment($give->id));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));
    }

    /** @param bool $voucherOnly Whether the saved pair contains only a voucher */
    #[DataProvider('singleSourceParts')]
    public function testLateMergeCartClearanceAfterCompletionUsesTheSavedPair(bool $voucherOnly): void
    {
        $loser = $this->person('Completed cart merge loser');
        $survivor = $this->person('Completed cart merge survivor');
        $product = self::product('pine-bark-5-l');
        if ($voucherOnly) {
            self::addSourceVoucher(self::SOURCE_EPOCH, self::voucherId('SPRING10'));
        } else {
            self::addSourceLine(self::SOURCE_EPOCH, $product, 3);
        }
        $transferId = $this->assign($loser);
        self::addSourceLine(self::NEXT_EPOCH, $product, 4);
        $nextId = $this->assign($survivor, self::NEXT_EPOCH);
        $give = $this->readyGive($survivor, $loser);
        $offer = new GuestCartTransferOfferSignalData($transferId, $loser);
        $cleared = new GuestCartTransferClearedSignalData($transferId, $loser);
        $this->deliver($loser, self::OFFER, $offer);
        $wake = $this->queuedTransferFrame(self::MERGE_WAKE);
        $this->dispatchMergeFrame($wake);
        $this->finishMergeExchange($give->operationId);
        self::assertSame([], self::accountLines($loser));
        self::assertNull(self::accountVoucherId($loser));
        self::assertSame($voucherOnly ? [] : [$product => 3], self::accountLines($survivor));
        self::assertSame($voucherOnly ? self::voucherId('SPRING10') : null, self::accountVoucherId($survivor));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));
        $this->dispatchMergeFrame($wake);
        self::assertFalse(Hilos::$sr->hasQueuedSignals());

        try {
            $this->deliver($loser, self::OFFER, $offer);
            self::fail('A completed merge replayed a guest cart receipt.');
        } catch (AgentException $failure) {
            self::assertInstanceOf(ValidationException::class, $failure->getPrevious());
        }
        self::assertSame([], $this->transferFrames($loser));
        $this->deliver($loser, self::CLEARED, $cleared);
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));

        $this->runRecoveryTick($this->startGuestCarts());
        $clearance = $this->queuedTransferFrame(self::CLEARED);
        self::assertEquals($cleared, $clearance->data->data);
        try {
            $wrongSender = new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE, (string)$loser);
            $this->deliver($loser, self::CLEARED, $cleared, $wrongSender);
            self::fail('A clearance with a different source address was accepted.');
        } catch (AgentException $failure) {
            self::assertStringContainsString('as the sender', $failure->getMessage());
        }
        $this->deliver($survivor, self::CLEARED, new GuestCartTransferClearedSignalData($transferId, $survivor));
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));

        $oldOwner = $this->personAgent($loser);
        $this->underAgent($oldOwner, $oldOwner->onStop(...));
        TruthSourceRegistry::unregisterAgent($oldOwner->getId());
        RtTruthSourceRegistry::unregisterAgent($oldOwner->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($oldOwner->getId()));
        Hilos::$db->forgetLoadedDataForTest();
        $restarted = new UserAgent((string)$loser);
        $this->underAgent($restarted, fn () => $this->startAgent($restarted));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->underAgent($restarted, static fn () => $restarted->onSignalAgent(
                $clearance->data, SignalSource::describe($clearance->signalSource), self::CLEARED,
            ));
            self::assertNull(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $loser));
        }
        self::assertSame([$product => 4], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($nextId, $survivor));
    }

    /** @return iterable<string, array{bool}> The only remaining source part */
    public static function singleSourceParts(): iterable
    {
        yield 'product' => [false];
        yield 'voucher' => [true];
    }

    public function testRejectsTheWrongSenderAddressPayloadAndStoredSource(): void
    {
        $userId = $this->person('Assigned recipient');
        $otherId = $this->person('Different recipient');
        $stock = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3);
        self::addSourceLine(self::NEXT_EPOCH, $stock, 4);
        $transferId = $this->assign($userId);
        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $this->drainSignals();

        $indexedSender = new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE, (string)$userId);
        foreach ([[$userId, $indexedSender], [$otherId, null]] as [$recipient, $sender]) {
            try {
                $this->deliver($recipient, self::OFFER, $offer, $sender);
                self::fail('The agent accepted the wrong sender or recipient.');
            } catch (AgentException $refusal) {
                self::assertSame(AgentException::class, $refusal::class);
            }
        }

        try {
            $this->deliver($userId, self::OFFER, new GuestCartTransferClearedSignalData($transferId, $userId));
            self::fail('The offer handler accepted a different DTO.');
        } catch (InvalidAgentSignalPayloadException $refusal) {
            self::assertStringContainsString(self::OFFER, $refusal->getMessage());
        }

        $this->deliver($otherId, self::OFFER, new GuestCartTransferOfferSignalData($transferId, $otherId));
        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData(PHP_INT_MAX, $userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));

        self::deleteSource();
        $this->deliver($userId, self::OFFER, $offer);
        $this->deliver($userId, self::CLEARED, new GuestCartTransferClearedSignalData($transferId, $userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame([], $this->transferFrames($userId));
        self::assertSame([], self::accountLines($userId));
        self::assertSame([], self::accountLines($otherId));
        self::assertSame([$stock => 4], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
    }

    public function testSecondPageOverflowRollsBackTheFirstPageAndAllowsReplay(): void
    {
        $userId = $this->person('Transfer above the cart limit');
        $first = self::product('pine-bark-5-l');
        $products = self::firstProducts(26);
        $last = $products[25];
        $source = array_fill_keys($products, 1);
        $source[$first] = 5;
        self::addAccountLine($userId, $first, 4);
        self::addAccountLine($userId, $last, self::MAX_CART_QUANTITY);
        foreach (array_reverse($source, true) as $productId => $quantity) {
            self::addSourceLine(self::SOURCE_EPOCH, $productId, $quantity);
        }
        self::addSourceLine(self::NEXT_EPOCH, $last, 7);
        $transferId = $this->assign($userId);
        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $held = Hilos::$db->userProductCarts->whereColumnIs(UserProductCart::user_id, $userId)->first();
        self::assertNotNull($held);
        self::assertSame($first, $held->productId);
        self::assertSame(
            array_slice($source, 0, 25, true),
            Hilos::$db->sessionProductCarts->storedCartQuantityPage(self::SOURCE_SESSION, self::SOURCE_EPOCH, 0),
        );
        self::assertSame(
            [$last => 1],
            Hilos::$db->sessionProductCarts->storedCartQuantityPage(self::SOURCE_SESSION, self::SOURCE_EPOCH, $products[24]),
        );
        $this->drainSignals();

        try {
            $this->deliver($userId, self::OFFER, $offer);
            self::fail('The sum exceeded the safe integer limit.');
        } catch (AgentException $refusal) {
            self::assertInstanceOf(ValidationException::class, $refusal->getPrevious());
            self::assertSame('The combined cart quantity is too large.', $refusal->getPrevious()->getMessage());
        }

        self::assertSame(4, $held->quantity);
        self::assertSame([$first => 4, $last => self::MAX_CART_QUANTITY], self::accountLines($userId));
        self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertEquals($source, Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertSame([], $this->transferFrames($userId));

        Database::sqlRun('UPDATE user_product_cart SET quantity = ? WHERE user_id = ? AND product_id = ?', [
            self::MAX_CART_QUANTITY - 1, $userId, $last,
        ]);
        $this->deliver($userId, self::OFFER, $offer);
        $expected = array_replace($source, [$first => 9, $last => self::MAX_CART_QUANTITY]);
        $receipt = [self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]];
        self::assertTrue(Hilos::$db->shopGuestCartTransfers->storedReceipt($transferId, $userId));
        self::assertSame(9, $held->quantity);
        self::assertEquals($expected, self::accountLines($userId));
        $frames = $this->transferFrames($userId);
        self::assertSame([EcommerceShopSignalConstants::CART_STATE, self::RECEIVED], array_column($frames, 0));
        self::assertEquals($expected, array_column($frames[0][1]['lines'], 'quantity', 'productId'));
        self::assertSame($receipt, $frames[1]);

        self::deleteSource();
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([$receipt], $this->transferFrames($userId));
        self::assertEquals($expected, self::accountLines($userId));
        self::assertSame([$last => 7], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::NEXT_EPOCH));
    }

    /** A receipt write failure rolls back the already written cart, restores held objects and emits no wake. */
    public function testMergeWakeReceiptFailureRollsBackProductsVoucherAndReceiptBeforeReplay(): void
    {
        $userId = $this->person('Recipient with an interrupted receipt');
        $product = self::product('pine-bark-5-l');
        $previous = self::voucherId('SPRING10');
        $incoming = self::voucherId('WEDDING15');
        $owner = $this->personAgent($userId);
        $this->underAgent($owner, static fn () => $owner->applyVoucher('WEDDING15'));
        self::addAccountLine($userId, $product, 2);
        self::addSourceLine(self::SOURCE_EPOCH, $product, 3);
        self::addSourceVoucher(self::SOURCE_EPOCH, $incoming);
        $transferId = $this->assign($userId);
        $offer = new GuestCartTransferOfferSignalData($transferId, $userId);
        $give = $this->readyGive($this->person('Interrupted receipt survivor'), $userId);
        self::assertSame(AccountOperationAnswerKind::Waits, $this->deliverMergeStep($give));
        $heldLine = Hilos::$db->userProductCarts->whereColumnIs(UserProductCart::user_id, $userId)->first();
        self::assertNotNull($heldLine);
        $heldVoucher = Hilos::$db->userVoucherCarts[$userId]->getObject();
        $heldTransfer = Hilos::$db->shopGuestCartTransfers[$transferId]->getObject();
        Database::sqlRun('UPDATE user_voucher_cart SET voucher_id = ? WHERE user_id = ?', [$previous, $userId]);
        self::assertSame($incoming, $heldVoucher->voucherId);
        $this->drainSignals();

        $probe = new class ((string)$transferId) implements SourceMirrorSubscriberInterface {
            public ?Closure $beforeRollback = null;

            /** @param string $transferKey Row whose receipt is interrupted */
            public function __construct(private readonly string $transferKey)
            {
            }

            /**
             * @param SourceChange $change Mutation reaching the mirror inside PRIMARY
             * @param SourceChangeProvenance $provenance Origin of the mutation
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === EcommerceShopDbContext::shopGuestCartTransfers && $change->sourceId === $this->transferKey
                    && $change->mutationType === TableMutationType::Update && $this->beforeRollback !== null
                ) {
                    $check = $this->beforeRollback;
                    $this->beforeRollback = null;
                    $check();
                    throw new RuntimeException('Injected guest cart receipt failure');
                }
            }
        };
        $probe->beforeRollback = static function () use ($userId, $product, $incoming, $transferId): void {
            self::assertSame([$product => 5], self::accountLines($userId));
            self::assertSame($incoming, self::accountVoucherId($userId));
            self::assertTrue(ShopGuestCartTransfer::getById($transferId)?->received);
        };
        SourceChangeBus::subscribe($probe);
        try {
            $this->deliver($userId, self::OFFER, $offer);
            self::fail('The receipt failure did not reach the transfer handler.');
        } catch (AgentException $failure) {
            self::assertInstanceOf(SourceChangeSubscriberException::class, $failure->getPrevious());
            self::assertSame('Injected guest cart receipt failure', $failure->getPrevious()->getPrevious()?->getMessage());
        } finally {
            $probe->beforeRollback = null;
        }

        self::assertSame([$product => 2], self::accountLines($userId));
        self::assertSame($previous, self::accountVoucherId($userId));
        self::assertFalse(ShopGuestCartTransfer::getById($transferId)?->received);
        self::assertSame(2, $heldLine->quantity);
        self::assertSame($heldVoucher, Hilos::$db->userVoucherCarts[$userId]->getObject());
        self::assertSame($incoming, $heldVoucher->voucherId);
        self::assertSame($heldTransfer, Hilos::$db->shopGuestCartTransfers[$transferId]->getObject());
        self::assertFalse($heldTransfer->received);
        self::assertSame([$product => 3], Hilos::$db->sessionProductCarts->storedCartQuantities(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertSame($incoming, Hilos::$db->sessionVoucherCarts->storedVoucherId(self::SOURCE_SESSION, self::SOURCE_EPOCH));
        self::assertSame([], $this->transferFrames($userId, expectNoVoucherWrite: true));

        $receipt = [self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]];
        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 5]], 'voucherId' => $incoming,
            ]],
            $receipt,
            [self::MERGE_WAKE, ['operationId' => $give->operationId]],
        ], $this->transferFrames($userId));
        self::assertSame(5, $heldLine->quantity);
        self::assertSame($incoming, $heldVoucher->voucherId);
        self::assertSame($incoming, self::accountVoucherId($userId));
        self::assertTrue($heldTransfer->received);

        $this->deliver($userId, self::OFFER, $offer);
        self::assertSame([$receipt], $this->transferFrames($userId, expectNoVoucherWrite: true));
        self::assertSame([$product => 5], self::accountLines($userId));
        self::assertSame($incoming, self::accountVoucherId($userId));
    }

    /** Manual confirmation, recreation and removal use the saved slot even before a mirror update. */
    public function testManualVoucherWritesReconcileTheSavedSlot(): void
    {
        $userId = $this->person('Account with a stale voucher slot');
        $owner = $this->personAgent($userId);
        $selected = self::voucherId('SPRING10');
        $other = self::voucherId('WEDDING15');
        $apply = fn () => $this->underAgent($owner, static fn () => $owner->applyVoucher('SPRING10'));
        $apply();
        $this->drainSignals();

        $held = Hilos::$db->userVoucherCarts[$userId]->getObject();
        Database::sqlRun('UPDATE user_voucher_cart SET voucher_id = ? WHERE user_id = ?', [$other, $userId]);
        self::assertSame($selected, $held->voucherId);
        $apply();
        $selectedFrame = [EcommerceShopSignalConstants::CART_STATE, ['userId' => $userId, 'lines' => [], 'voucherId' => $selected]];
        self::assertSame([$selectedFrame], $this->transferFrames($userId));
        self::assertSame($selected, self::accountVoucherId($userId));

        $apply();
        self::assertSame([], $this->transferFrames($userId, expectNoVoucherWrite: true));

        Database::sqlRun('DELETE FROM user_voucher_cart WHERE user_id = ?', [$userId]);
        self::assertSame($held, Hilos::$db->userVoucherCarts[$userId]->getObject());
        $stop = new RuntimeException('Roll back the recreated voucher slot');
        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($apply, $selected, $userId, $stop): void {
                $apply();
                self::assertSame($selected, self::accountVoucherId($userId));
                throw $stop;
            });
        } catch (RuntimeException $failure) {
            self::assertSame($stop, $failure);
        }
        self::assertSame([], $this->transferFrames($userId, expectNoVoucherWrite: true));
        self::assertNull(self::accountVoucherId($userId));
        self::assertSame($held, Hilos::$db->userVoucherCarts[$userId]->getObject());

        $apply();
        self::assertSame([$selectedFrame], $this->transferFrames($userId));
        self::assertSame($selected, self::accountVoucherId($userId));
        self::assertNotSame($held, Hilos::$db->userVoucherCarts[$userId]->getObject());

        $this->underAgent($owner, $owner->removeVoucher(...));
        $this->drainSignals();
        self::assertFalse(isset(Hilos::$db->userVoucherCarts[$userId]));
        Database::sqlRun('INSERT INTO user_voucher_cart (user_id, voucher_id) VALUES (?, ?)', [$userId, $other]);
        $this->underAgent($owner, $owner->removeVoucher(...));
        self::assertNull(self::accountVoucherId($userId));
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, ['userId' => $userId, 'lines' => [], 'voucherId' => null]],
        ], $this->transferFrames($userId));
    }

    /** @return iterable<string, array{?string, ?string, bool, ?string}> Original slot, applied code, refusal, final code */
    public static function manualVoucherChoices(): iterable
    {
        yield 'apply a different code' => ['WEDDING15', 'SPRING10', false, 'SPRING10'];
        yield 'confirm the same code' => ['SPRING10', 'SPRING10', false, 'SPRING10'];
        yield 'remove the selected code' => ['SPRING10', null, false, null];
        yield 'remove from an empty slot' => [null, null, false, null];
        yield 'apply an inactive code' => ['SPRING10', 'WELCOME5', true, 'WEDDING15'];
    }

    /**
     * @param ?string $previousCode Code selected before the guest assignment
     * @param ?string $apply Code to apply manually, or null for Remove
     * @param bool $refused Whether the manual choice fails
     * @param ?string $finalCode Code after receipt of the guest cart
     */
    #[DataProvider('manualVoucherChoices')]
    public function testManualVoucherChoicesSetPrecedenceForPendingOffers(
        ?string $previousCode,
        ?string $apply,
        bool $refused,
        ?string $finalCode,
    ): void {
        $userId = $this->person('Recipient who chooses a voucher');
        $owner = $this->personAgent($userId);
        if ($previousCode !== null) {
            $this->underAgent($owner, static fn () => $owner->applyVoucher($previousCode));
        }
        $product = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 1);
        self::addSourceVoucher(self::SOURCE_EPOCH, self::voucherId('WEDDING15'));
        $transferId = $this->assign($userId);
        self::assertFalse(ShopGuestCartTransfer::getById($transferId)?->voucher_superseded);
        $this->drainSignals();

        try {
            $this->underAgent($owner, $apply === null ? $owner->removeVoucher(...) : static fn () => $owner->applyVoucher($apply));
            self::assertFalse($refused);
        } catch (ValidationException $failure) {
            self::assertTrue($refused);
            self::assertSame('This voucher is inactive', $failure->getMessage());
        }
        self::assertSame(!$refused, ShopGuestCartTransfer::getById($transferId)?->voucher_superseded);
        $manuallyChosen = $refused ? $previousCode : $apply;
        $manualVoucher = $manuallyChosen === null ? null : self::voucherId($manuallyChosen);
        self::assertSame($manualVoucher, self::accountVoucherId($userId));
        $unchanged = $manuallyChosen === $previousCode;
        self::assertSame($unchanged ? [] : [
            [EcommerceShopSignalConstants::CART_STATE, ['userId' => $userId, 'lines' => [], 'voucherId' => $manualVoucher]],
        ], $this->transferFrames($userId, expectNoVoucherWrite: $unchanged));

        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData($transferId, $userId));
        $finalVoucher = $finalCode === null ? null : self::voucherId($finalCode);
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 1]], 'voucherId' => $finalVoucher,
            ]],
            [self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]],
        ], $this->transferFrames($userId));
        self::assertSame($finalVoucher, self::accountVoucherId($userId));
    }

    /** @return iterable<string, array{?string, bool}> Later raw voucher choice and whether the receipt is already saved */
    public static function laterGuestVoucherOffers(): iterable
    {
        yield 'fresh offer with a different ID' => ['WELCOME5', false];
        yield 'fresh offer with the selected ID' => ['SPRING10', false];
        yield 'fresh offer without a voucher' => [null, false];
        yield 'replayed receipt' => ['WELCOME5', true];
    }

    /**
     * @param ?string $laterCode Voucher on the later source, or null for products only
     * @param bool $received Whether this offer is a replay of a saved receipt
     */
    #[DataProvider('laterGuestVoucherOffers')]
    public function testLaterOffersOnlySupersedeEarlierVouchersOnAFreshVoucherChoice(?string $laterCode, bool $received): void
    {
        $userId = $this->person('Recipient of out-of-order vouchers');
        $product = self::product('pine-bark-5-l');
        $owner = $this->personAgent($userId);
        $this->underAgent($owner, static fn () => $owner->applyVoucher('SPRING10'));
        $earlierVoucher = self::voucherId('WEDDING15');
        $currentVoucher = self::voucherId('SPRING10');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 2);
        self::addSourceVoucher(self::SOURCE_EPOCH, $earlierVoucher);
        $earlier = $this->assign($userId);
        self::addSourceLine(self::NEXT_EPOCH, $product, 3);
        $laterVoucher = $laterCode === null ? null : self::voucherId($laterCode);
        if ($laterVoucher !== null) {
            self::addSourceVoucher(self::NEXT_EPOCH, $laterVoucher);
        }
        $later = $this->assign($userId, self::NEXT_EPOCH);
        if ($received) {
            // Storage already has the later cart and receipt while this worker still has its pending mirror.
            self::addAccountLine($userId, $product, 3);
            Database::sqlRun('UPDATE shop_guest_cart_transfer SET received = TRUE WHERE id = ?', [$later]);
        }
        $this->drainSignals();

        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData($later, $userId));
        $laterReceipt = [self::RECEIVED, ['transferId' => $later, 'userId' => $userId]];
        $superseded = $laterVoucher !== null && !$received;
        $chosenByLater = $superseded ? $laterVoucher : $currentVoucher;
        $laterCart = [EcommerceShopSignalConstants::CART_STATE, [
            'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 3]], 'voucherId' => $chosenByLater,
        ]];
        self::assertSame(
            $received ? [$laterReceipt] : [$laterCart, $laterReceipt],
            $this->transferFrames($userId, expectNoVoucherWrite: $chosenByLater === $currentVoucher),
        );
        self::assertSame($superseded, ShopGuestCartTransfer::getById($earlier)?->voucher_superseded);
        self::assertTrue(ShopGuestCartTransfer::getById($later)?->received);
        self::assertSame($chosenByLater, self::accountVoucherId($userId));

        $this->deliver($userId, self::OFFER, new GuestCartTransferOfferSignalData($earlier, $userId));
        $finalVoucher = $superseded ? $chosenByLater : $earlierVoucher;
        self::assertSame([
            [EcommerceShopSignalConstants::CART_STATE, [
                'userId' => $userId, 'lines' => [['productId' => $product, 'quantity' => 5]], 'voucherId' => $finalVoucher,
            ]],
            [self::RECEIVED, ['transferId' => $earlier, 'userId' => $userId]],
        ], $this->transferFrames($userId, expectNoVoucherWrite: $superseded));
        self::assertSame([$product => 5], self::accountLines($userId));
        self::assertSame($finalVoucher, self::accountVoucherId($userId));
    }

    public function testErasureRefusesFreshWritesAndStillAllowsAnExistingReceipt(): void
    {
        $userId = $this->person('Transfer and erasure');
        $stock = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $stock, 3);
        self::addSourceLine(self::NEXT_EPOCH, $stock, 2);
        $transferId = $this->assign($userId);
        $pendingId = $this->assign($userId, self::NEXT_EPOCH);
        $committed = new GuestCartTransferOfferSignalData($transferId, $userId);
        $pending = new GuestCartTransferOfferSignalData($pendingId, $userId);
        $this->deliver($userId, self::OFFER, $committed);
        $this->drainSignals();

        $now = TimeHelper::nowMs();
        Database::sqlRun(
            'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at, started_at) VALUES (?, ?, ?, ?)',
            [$userId, $now, $now, $now],
        );

        try {
            $this->deliver($userId, self::OFFER, $pending);
            self::fail('A transfer wrote to an account whose erasure had begun.');
        } catch (AgentException $refusal) {
            self::assertInstanceOf(AccountErasingException::class, $refusal->getPrevious());
            self::assertFalse(Hilos::$db->shopGuestCartTransfers->storedReceipt($pendingId, $userId));
        }
        $this->deliver($userId, self::OFFER, $committed);

        self::assertSame([[self::RECEIVED, ['transferId' => $transferId, 'userId' => $userId]]], $this->transferFrames($userId));
        self::assertSame([$stock => 3], self::accountLines($userId));
    }

    private function startGuestCarts(): GuestCartsLibraryAgent
    {
        $carts = new GuestCartsLibraryAgent();
        $this->underAgent($carts, fn () => $this->startAgent($carts));

        return $carts;
    }

    private function runRecoveryTick(GuestCartsLibraryAgent $carts): void
    {
        self::assertNotNull($carts->armCronRuleForTest(self::RECOVERY_RULE));
        $this->underAgent($carts, $carts->onTick(...));
    }

    private function sendSessionStateWithoutTabs(Session $session): void
    {
        (new EcommerceShopAgent())->onSignalAgent(
            new AgentSignalData(new SessionStateSignalData($session->token, $session->id, $session->userId, [])),
            '',
            HilosSignalConstants::HILOS_SESSION_STATE,
        );
    }

    /** @return list<int> Saved transfer keys offered since the last drain */
    private function queuedOfferIds(): array
    {
        $ids = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === self::OFFER) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(GuestCartTransferOfferSignalData::class, $signal->data->data);
                $ids[] = $signal->data->data->transferId;
            }
        }

        return $ids;
    }

    private function queuedTransferFrame(string $name): SignalDTO
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                $frame = SignalDTO::fromJson($signal->toJson());
                self::assertInstanceOf(AgentSignalData::class, $frame->data);

                return $frame;
            }
        }

        self::fail('No ' . $name . ' frame was queued.');
    }

    private function dispatchSourceFrame(GuestCartsLibraryAgent $carts, SignalDTO $frame): void
    {
        $this->underAgent($carts, static fn () => $carts->onSignalAgent(
            $frame->data,
            SignalSource::describe($frame->signalSource),
            $frame->signalName->getName(),
        ));
    }

    private function refuseSourceFrame(
        GuestCartsLibraryAgent $carts,
        string $name,
        SignalDataInterface $payload,
        SignalSourceInterface $sender,
    ): void {
        try {
            $this->underAgent($carts, static fn () => $carts->onSignalAgent(new AgentSignalData($payload), SignalSource::describe($sender), $name));
            self::fail('The cart source accepted a transfer frame from a different sender.');
        } catch (AgentException $refusal) {
            self::assertStringContainsString('as the sender', $refusal->getMessage());
        }
    }

    /** Delivers an IPC roundtrip under the addressed agent's execution context. */
    private function deliver(int $recipient, string $name, SignalDataInterface $payload, ?SignalSource $sender = null): void
    {
        $owner = $this->personAgent($recipient);
        $frame = new SignalDTO(
            $sender ?? new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name),
            new AgentSignalData($payload),
        );
        $delivered = SignalDTO::fromJson($frame->toJson());
        self::assertInstanceOf(AgentSignalData::class, $delivered->data);
        $this->underAgent($owner, static fn () => $owner->onSignalAgent(
            $delivered->data,
            SignalSource::describe($delivered->signalSource),
            $delivered->signalName->getName(),
        ));
    }

    /**
     * @param int $userId Recipient whose frames are expected
     * @param bool $expectNoVoucherWrite Whether emitting a voucher slot fact fails the test
     * @return list<array{string, array<string, mixed>}> The receipt, cart state and any merge wake announced by the recipient
     */
    private function transferFrames(int $userId, bool $expectNoVoucherWrite = false): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($name === self::RECEIVED || $name === self::MERGE_WAKE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertEquals($this->personAgent($userId)->getAgentSignalSource(), $signal->signalSource);
                $frames[] = [$name, $signal->data->data->toArray()];
            } elseif ($name === EcommerceShopSignalConstants::CART_STATE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(CartGroup::forUser($userId), $signal->data->targetGroup);
                $frames[] = [$name, $signal->data->data->toArray()];
            } elseif ($expectNoVoucherWrite && $signal->data instanceof DbSyncSignalDataInterface) {
                self::assertNotSame(EcommerceShopDbContext::userVoucherCarts, $signal->data->collectionKey);
            }
        }

        return $frames;
    }

    /**
     * Delivers a typed frame to the shop worker named by the topology.
     *
     * @param SignalDTO $queued Merge wake, participant ask, handover or answer
     */
    private function dispatchMergeFrame(SignalDTO $queued): void
    {
        $frame = SignalDTO::fromJson($queued->toJson());
        $destinations = Hilos::$sr->getDestinations($frame);
        self::assertCount(1, $destinations);
        $destination = $destinations[0];
        self::assertInstanceOf(AgentDestination::class, $destination);
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        if ($destination->agentType === HilosAgentType::HILOS_SESSIONS_LIBRARY) {
            self::assertNull($destination->agentIndex);
            $agent = $this->sessionsLibrary();
        } else {
            self::assertSame(HilosAgentType::HILOS_USER, $destination->agentType);
            self::assertNotNull($destination->agentIndex);
            $agent = $this->personAgent((int)$destination->agentIndex);
        }
        $name = $frame->signalName->getName();
        $data = Hilos::$sr->createAgentSignalPayloadDTO($name, $frame->data);
        $this->underAgent($agent, static fn () => $agent->onSignalAgent($data, SignalSource::describe($frame->signalSource), $name));
    }

    /**
     * Delivers the queued merge work to completion while the guest library remains idle.
     *
     * @param int $operationId Merge whose cart source has yet to be swept
     */
    private function finishMergeExchange(int $operationId): void
    {
        for ($frameCount = 0; ($frame = Hilos::$sr->getNextQueuedSignal()) !== null; $frameCount++) {
            self::assertLessThan(100, $frameCount, 'The merge exchange must settle without replay loops.');
            if ($frame->data instanceof AgentSignalData
                && ($frame->data->data instanceof AccountOperationAskSignalData
                    || $frame->data->data instanceof AccountMergeHandoverSignalData
                    || $frame->data->data instanceof AccountMergePersonalHandoverSignalData
                    || $frame->data->data instanceof AccountOperationAnswerSignalData)) {
                $this->dispatchMergeFrame($frame);
            }
        }
        $operation = Hilos::$db->accountOperations->fresh($operationId);
        self::assertSame(AccountOperationOutcome::Completed->value, $operation?->outcome);
        self::assertNotNull($operation->finishedAt);
    }

    /** @return AccountOperationAssignment Real Go/Give duty after both person owners reserve their work */
    private function readyGive(int $survivor, int $loser): AccountOperationAssignment
    {
        $this->personAgent($survivor);
        $this->personAgent($loser);
        $holder = $this->sessionsLibrary();
        $operation = $this->underAgent($holder, static fn () =>
            AccountMergeOpening::open($survivor, $loser, new AccountMergeChoice(null, null, null, null)),
        );
        $preparations = $this->underAgent($holder, static fn () => AccountMergeCoordinator::begin($operation));
        self::assertCount(2, $preparations);
        foreach ($preparations as $preparation) {
            self::assertSame(AccountOperationAnswerKind::Done, $this->deliverMergeStep($preparation));
        }
        $go = $this->underAgent($holder, static fn () => AccountMergeCoordinator::begin($operation));
        self::assertCount(1, $go);
        self::assertSame(AccountMergeDuty::Give->value, $go[0]->assignmentKey);

        return $go[0];
    }

    /** @return AccountOperationAnswerKind Answer from the shop's actual participant handler */
    private function deliverMergeStep(AccountOperationAssignment $assignment): AccountOperationAnswerKind
    {
        $this->drainSignals();
        self::runMergeStep($this->personAgent($assignment->userId), $assignment);

        return self::readMergeAnswer($assignment);
    }

    private static function runMergeStep(UserAgent $owner, AccountOperationAssignment $assignment): void
    {
        $ask = new AccountOperationAskSignalData($assignment->operationId, $assignment->stage, $assignment->id, $assignment->owner->ownerIndex);
        $sender = SignalSource::describe(new SignalSource(SignalSource::AGENT, HilosAgentType::HILOS_SESSIONS_LIBRARY));
        ExecutionContext::run(new ExecutionFrame(agentId: $owner->getId()), static fn () => $owner->onSignalAgent(
            new AgentSignalData($ask), $sender, HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP,
        ));
    }

    private static function readMergeAnswer(AccountOperationAssignment $assignment): AccountOperationAnswerKind
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof AgentSignalData && $frame->data->data instanceof AccountOperationAnswerSignalData) {
                self::assertSame($assignment->id, $frame->data->data->assignmentId);

                return $frame->data->data->answer;
            }
        }
        self::fail('The participant did not answer its merge duty.');
    }

    /**
     * Give runs in its own PHP process so its PRIMARY can commit while the caller holds a snapshot.
     * The process exit is the commit barrier and the child checks the account lock before committing.
     */
    private static function giveInPeerProcess(AccountOperationAssignment $give): void
    {
        $process = proc_open([
            PHP_BINARY,
            '-r',
            'require $argv[1]; require $argv[2]; Demo\\EcommerceShop\\Tests\\Integration\\GuestCartTransferTest::runPeerGive((int)$argv[3]);',
            dirname(__DIR__, 2) . '/backend/Bootstrap/phpunit.php',
            __FILE__,
            (string)$give->id,
        ], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
    }

    /** @param int $assignmentId Persisted Go/Give duty reached by the child process */
    public static function runPeerGive(int $assignmentId): void
    {
        Database::initialize(initHilos: true);
        Hilos::initSignalRouter(new EcommerceShopSignalRouter());
        Database::handlerStart();
        $peer = self::mergePeer();
        try {
            $give = Hilos::$db->accountOperationAssignments[$assignmentId];
            $owner = new UserAgent((string)$give->userId);
            OwnershipDeclaration::claimAll($owner);
            $probed = self::withUserLockProbe($peer, $give->userId, HilosDbContext::accountMergeCopies, static fn () =>
                self::runMergeStep($owner, $give),
            );
            self::assertSame(AccountOperationAnswerKind::Done, self::readMergeAnswer($give));
            // The loser row is also updated by Give before the copy is announced.
            self::assertUserLockHeld($probed);
        } finally {
            $peer->close();
            self::assertSame([], Database::handlerEnd());
        }
    }

    private function seedMergePassword(int $loser): void
    {
        $now = TimeHelper::nowMs();
        Database::sqlRun(
            'INSERT INTO hilos_identity (user_id, type, identifier, secret, verified, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, TRUE, ?, ?)',
            [$loser, IdentityType::PASSWORD, self::MERGE_EMAIL, Hilos::$db->identities->hashPassword(self::MERGE_PASSWORD), $now, $now],
        );
    }

    /** @return SignalDTO Password proof parked between the two auth owners */
    private function passwordGrant(Session $session, int $loser): SignalDTO
    {
        $users = $this->usersLibrary();
        $this->registerLoginTab($session);
        $this->drainSignals();
        $users->beginActionDispatch(self::MERGE_REQUEST);
        try {
            $this->underAgent($users, static fn () => $users->onAgentAction(
                self::MERGE_LOGIN_KEY,
                HilosSignalConstants::HILOS_LOGIN,
                new LoginActionDTO(self::MERGE_EMAIL, self::MERGE_PASSWORD),
            ));
            self::assertTrue($users->actionReplyDeferred());
        } finally {
            $users->endActionDispatch();
        }
        $grant = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::HILOS_AUTH_SESSION_GRANT, $grant?->signalName->getName());
        self::assertSame($loser, $grant->data->data->userId);
        self::assertSame(self::MERGE_REQUEST, $grant->data->data->requestId);

        return $grant;
    }

    private function deliverLoginGrant(SignalDTO $grant, bool $refused = false): SessionStateSignalData
    {
        $this->drainSignals();
        $holder = $this->sessionsLibrary();
        $this->underAgent($holder, static fn () => $holder->onSignalAgent(
            Hilos::$sr->createAgentSignalPayloadDTO($grant->signalName->getName(), $grant->data),
            SignalSource::describe($grant->signalSource),
            $grant->signalName->getName(),
        ));
        $answer = null;
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($refused) {
                self::assertNotSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED, $frame->signalName->getName());
            }
            if ($frame->data instanceof AgentSignalData && $frame->data->data instanceof SessionStateSignalData) {
                if ($refused) {
                    self::assertNull($frame->data->data->userId);
                    self::assertNull($frame->data->data->rotationTicket);
                }
                if ($frame->data->data->requestId === self::MERGE_REQUEST) {
                    self::assertNull($answer);
                    $answer = $frame->data->data;
                }
            }
        }
        self::assertNotNull($answer);
        self::assertSame(HilosSignalConstants::HILOS_LOGIN, $answer->action);
        self::assertSame([self::MERGE_LOGIN_KEY], $answer->acceptKeys);

        return $answer;
    }

    private function registerLoginTab(Session $session): void
    {
        RtTruthSourceRegistry::registerDaemon(EcommerceShopRtContext::connections);
        ExecutionContext::run(new ExecutionFrame(), static function () use ($session): void {
            Hilos::$rt->connections->actions->register(self::MERGE_LOGIN_KEY, null, $session->token, $session->id);
        });
    }

    private function deliverToSessions(string $name, SignalDataInterface $payload): void
    {
        $holder = $this->sessionsLibrary();
        $this->underAgent($holder, static fn () => $holder->onSignalAgent(new AgentSignalData(data: $payload), '', $name));
    }

    /**
     * @param Session $session Guest session whose cart must survive the refusal
     * @param Closure(): void $dispatch Handler that attempts to bind the session
     * @return list<SignalDataInterface> Replies and session states queued by the holder
     */
    private function withRefusedSessionWrite(Session $session, Closure $dispatch): array
    {
        $id = $session->id;
        $token = $session->token;
        $product = self::product('pine-bark-5-l');
        self::addSourceLine(self::SOURCE_EPOCH, $product, 3, $id);
        $this->drainSignals();
        $sessions = Hilos::$db->sessions;
        $actions = $sessions->getItemActionsClass();
        $rotations = count(Hilos::$rt->hilosSessionRotations);
        $sessions->setItemActionsClass(RefusingGuestSessionActions::class);
        $sessions->forgetCachedItem($id);
        RtTruthSourceRegistry::registerDaemon(HilosSessionRotation::RT_COLLECTION);
        try {
            $dispatch();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(HilosSessionRotation::RT_COLLECTION);
            $sessions->setItemActionsClass($actions);
            $sessions->forgetCachedItem($id);
        }

        self::assertSame(self::SOURCE_EPOCH, $sessions->activeGuestCartEpoch($id, $token));
        self::assertSame([$product => 3], Hilos::$db->sessionProductCarts->storedCartQuantities($id, self::SOURCE_EPOCH));
        self::assertNull(self::savedAssignment($id, self::SOURCE_EPOCH));
        self::assertSame($rotations, count(Hilos::$rt->hilosSessionRotations));
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED, $signal->signalName->getName());
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData || $payload instanceof WebSocketSignalData) {
                $payload = $payload->data;
            }
            if ($payload instanceof SessionStateSignalData) {
                self::assertSame($token, $payload->sessionToken);
                self::assertNull($payload->userId);
                self::assertNull($payload->rotationTicket);
                self::assertNull($payload->pendingAck);
            }
            if (
                $payload instanceof SessionStateSignalData
                || $payload instanceof CommandReplyDTO
                || $payload instanceof OAuthResultSignalData
                || $payload instanceof AuthConvergeSignalData
            ) {
                $frames[] = $payload;
            }
        }

        return $frames;
    }

    private static function mergePeer(): mysqli
    {
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);

        return new mysqli($config->host, $config->user, $config->password, $config->database, $config->port, $config->socket);
    }

    /**
     * @param mysqli $peer Independent connection to the committed fixtures
     * @param int $userId Account that must stay locked through the write
     * @param string $sourceKey Source mirror to observe before commit
     * @param Closure(): void $operation Actual owner handler
     * @return list<?int> Error codes from immediate lock attempts, null for an open row
     */
    private static function withUserLockProbe(mysqli $peer, int $userId, string $sourceKey, Closure $operation): array
    {
        $probe = new class ($sourceKey) implements SourceMirrorSubscriberInterface {
            public ?Closure $check = null;

            public function __construct(private readonly string $sourceKey)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === $this->sourceKey) {
                    ($this->check)?->__invoke();
                }
            }
        };
        $outcomes = [];
        $probe->check = static function () use ($peer, $userId, &$outcomes): void {
            $peer->begin_transaction();
            try {
                $peer->execute_query('SELECT id FROM hilos_user WHERE id = ? LOCK IN SHARE MODE NOWAIT', [$userId])->free();
                $outcomes[] = null;
            } catch (mysqli_sql_exception $failure) {
                $outcomes[] = $failure->getCode();
            } finally {
                $peer->rollback();
            }
        };
        SourceChangeBus::subscribe($probe);
        try {
            $operation();
        } finally {
            $probe->check = null;
        }

        return $outcomes;
    }

    /** @param list<?int> $outcomes Results of probing the account lock from a peer */
    private static function assertUserLockHeld(array $outcomes): void
    {
        self::assertNotEmpty($outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame(LockWaitTimeoutException::MYSQL_ERROR_CODE, $outcome);
        }
    }

    private function person(string $name): int
    {
        $userId = Hilos::$db->users->actions->createWithName($name)->id ?? self::fail('The recipient was not persisted.');
        $this->userIds[] = $userId;

        return $userId;
    }

    private function guest(): Session
    {
        $session = $this->underAgent($this->sessionsLibrary(), static fn () => Hilos::$db->sessions->actions->createAnonymous(SessionToken::mint()));
        self::assertNotNull($session->id);
        $this->sessionIds[] = $session->id;

        return $session;
    }

    private static function savedAssignment(int $sessionId, int $epoch): ?int
    {
        $id = Database::sql(
            'SELECT id FROM shop_guest_cart_transfer WHERE session_id = ? AND cart_epoch = ?',
            [$sessionId, $epoch],
        )->field('id');

        return $id === null ? null : (int)$id;
    }

    private function assign(int $userId, int $epoch = self::SOURCE_EPOCH): int
    {
        $this->underAgent($this->sessionsLibrary(), static function () use ($userId, $epoch): void {
            Hilos::$db->shopGuestCartTransfers->actions->create(self::SOURCE_SESSION, $epoch, $userId);
        });
        $id = self::savedAssignment(self::SOURCE_SESSION, $epoch);
        self::assertNotNull($id);

        return $id;
    }

    private static function product(string $slug): int
    {
        $id = Database::sql('SELECT id FROM product WHERE slug = ?', [$slug])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    /** @return list<int> IDs in catalog key order */
    private static function firstProducts(int $limit): array
    {
        $products = array_map('intval', array_column(Database::sql('SELECT id FROM product ORDER BY id LIMIT ?', [$limit])->rows(), 'id'));
        self::assertCount($limit, $products);

        return $products;
    }

    private static function addSourceLine(int $epoch, int $productId, int $quantity, int $sessionId = self::SOURCE_SESSION): void
    {
        Database::sqlRun(
            'INSERT INTO session_product_cart (session_id, cart_epoch, product_id, quantity) VALUES (?, ?, ?, ?)',
            [$sessionId, $epoch, $productId, $quantity],
        );
    }

    /**
     * @param int $epoch Source epoch of the binding
     * @param int $voucherId Catalog choice saved on the source
     */
    private static function addSourceVoucher(int $epoch, int $voucherId): void
    {
        Database::sqlRun(
            'INSERT INTO session_voucher_cart (session_id, cart_epoch, voucher_id) VALUES (?, ?, ?)',
            [self::SOURCE_SESSION, $epoch, $voucherId],
        );
    }

    /**
     * @param string $code Seeded voucher code
     * @return int Persisted voucher ID
     */
    private static function voucherId(string $code): int
    {
        $id = Database::sql('SELECT id FROM voucher WHERE code = ?', [$code])->field('id');
        self::assertNotNull($id);

        return (int)$id;
    }

    /**
     * @param int $userId Account with the voucher slot
     * @return ?int Persisted selection, or null for an empty slot
     */
    private static function accountVoucherId(int $userId): ?int
    {
        $id = Database::sql('SELECT voucher_id FROM user_voucher_cart WHERE user_id = ?', [$userId])->field('voucher_id');

        return $id === null ? null : (int)$id;
    }

    private static function addAccountLine(int $userId, int $productId, int $quantity): void
    {
        Database::sqlRun(
            'INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, ?)',
            [$userId, $productId, $quantity],
        );
    }

    private static function deleteSource(): void
    {
        Database::sqlRun('DELETE FROM session_product_cart WHERE session_id = ? AND cart_epoch = ?', [self::SOURCE_SESSION, self::SOURCE_EPOCH]);
    }

    /** @return array<int, int> Stored account quantities by product ID */
    private static function accountLines(int $userId): array
    {
        $lines = [];
        foreach (Database::sql('SELECT product_id, quantity FROM user_product_cart WHERE user_id = ? ORDER BY id', [$userId])->rows() as $row) {
            $lines[(int)$row['product_id']] = (int)$row['quantity'];
        }

        return $lines;
    }
}

/** Refuses at the session writer after the handler has accepted the proof or command. */
final class RefusingGuestSessionActions extends FrameworkSessionActions
{
    /** @inheritDoc */
    public function bindUser(int $userId): void
    {
        throw new AccountMergedSignInException();
    }

    /** @inheritDoc */
    public function rotateTokenAndBindUser(string $newToken, int $userId): void
    {
        throw new AccountMergedSignInException();
    }
}
