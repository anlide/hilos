<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\ProfileNameCommands;
use Hilos\Auth\Library\DTO\ProfileNameSetActionDTO;
use Hilos\Auth\Session\SessionAck;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Item\HilosProfileNameChange;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\UserRename;
use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Legal\LegalSignificance;
use Hilos\Moderation\DTO\ModerationCheckSignalData;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationDecision;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationReason;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationDraft;
use Hilos\Notification\NotificationSeverity;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Auth\Library\DTO\ProfileNameAdvanceSignalData;
use Hilos\Auth\Library\DTO\ProfileNameContinueSignalData;
use Hilos\Auth\Session\DTO\ProfileNameStateSignalData;
use Hilos\Auth\Session\DTO\LogoutActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AdminRenameSignalData;
use Hilos\Users\DTO\UserRenameDoneSignalData;
use Hilos\Users\DTO\UserRenameSignalData;
use Hilos\Users\ProfileNameRefusal;
use Hilos\Users\UserNotificationType;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real dispatch, shared checks, owner transaction and library completion, without a rollback root. */
final class ProfileNameIntegrationTest extends ProfileIntegrationTestCase
{
    private ProfileNameTestLibrary $nameLibrary;
    private PageSignalRouter $dispatcher;
    private string $previousAppClass;
    private ?HilosNotifier $previousNotify;
    private int $personHops = 0;
    private int $expectedAuthor = self::USER_ID;
    private string $expectedAction = HilosSignalConstants::PROFILE_NAME_SET;
    private ?SignalDTO $heldRename = null;
    private ?UserRenameDoneSignalData $lastRenameDone = null;

    /** @var list<SignalDTO> Outgoing frames not yet consumed by an assertion */
    private array $outbox = [];

    /** @return RtContext Profile tabs with the shared moderation queue */
    protected function createRuntime(): RtContext
    {
        return new ProfileNameTestRuntime();
    }

    /** Installs only this fixture's library claims, leaving the shared test base unchanged. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['hilos_file', 'hilos_user_photo'] as $table) {
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}.sql"));
        }
        $this->previousAppClass = Hilos::appClass();
        $this->previousNotify = Hilos::$notify;
        Hilos::$notify = new HilosNotifier();
        ProfileNameTestSettings::$timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;
        Hilos::$setting = new ProfileNameTestSettings(ProfileNameTestSettingsCatalog::class);
        ProfileNameModeratedHilos::initBrowser(new ProfileNameTestBrowser());
        Hilos::$sr = new ProfileNameTestRouter();
        $this->nameLibrary = new ProfileNameTestLibrary();
        OwnershipDeclaration::claimAll($this->nameLibrary);
        OwnershipDeclaration::claimAll($this->holder);
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());
        $this->dispatcher = new PageSignalRouter(
            new HilosPageFactory($this->nameLibrary, ProfileNameModeratedHilos::class), new ActionRouteConfig(),
        );
        AccountStandingResolver::forgetAll();
        $this->confirmStepUp(StepUpOperationKey::CHANGE_NAME);
    }

    /** Restores the facade and releases only the claims this fixture added. */
    protected function tearDown(): void
    {
        AccountStandingResolver::forgetAll();
        RtStaleness::clear(StateHilosProfileNameChange::RT_COLLECTION, [ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN)]);
        RtStaleness::clear(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY]);
        TruthSourceRegistry::unregisterAgent($this->nameLibrary->getId());
        RtTruthSourceRegistry::unregisterAgent($this->nameLibrary->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->nameLibrary->getId()));
        TruthSourceRegistry::unregisterAgent($this->holder->getId());
        RtTruthSourceRegistry::unregisterAgent($this->holder->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->holder->getId()));
        Hilos::$notify = $this->previousNotify;
        $this->previousAppClass::initBrowser();
        foreach (['hilos_user_photo', 'hilos_file'] as $table) {
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}_down.sql"));
        }
        parent::tearDown();
    }

    /** No moderation feature means there is no queue to access, even for a repeat. */
    public function testWithoutModerationWritesThroughOwnerOnce(): void
    {
        ProfileNameImmediateHilos::initBrowser(new ProfileNameTestBrowser());
        $rt = new ProfileIntegrationRtContext();
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        self::assertFalse(Hilos::hasFeature(HilosFeature::MODERATION));
        self::assertNotContains('hilosModerationChecks', $rt->collectionKeys());
        Hilos::$rt->connectionsSource()->add(ProfileIntegrationConnection::create('same-browser-tab', self::USER_ID, self::SESSION_TOKEN));
        self::seedSession(str_repeat('d', 32), self::USER_ID, '2026-10-01 10:00:00', null);
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), static function (): void {
            $session = Hilos::$db->sessions->findByToken(self::OTHER_SESSION_TOKEN);
            $session->actions->setImpersonator(self::ADMIN_USER_ID);
            $session->actions->holdPendingAck(SessionAck::SIGNED_IN);
        });
        $sessionsBefore = Database::sql('SELECT * FROM `hilos_session` ORDER BY `id`')->rows();
        $confirmationBefore = Database::sql('SELECT * FROM `hilos_step_up` ORDER BY `id`')->rows();

        $this->dispatchName('  Grace  ');
        $this->carry();
        $this->assertRenamedOnce('Grace');
        $this->assertSessionIdentity('Grace', [self::ACCEPT_KEY, 'same-browser-tab', self::OTHER_ACCEPT_KEY]);
        $states = $this->take(HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertCount(2, $states);
        self::assertSame([self::ACCEPT_KEY, 'same-browser-tab'], $states[0]->data->data->acceptKeys);
        self::assertSame(self::SESSION_TOKEN, $states[0]->data->data->sessionToken);
        self::assertNull($states[0]->data->data->pendingAck);
        self::assertSame([self::OTHER_ACCEPT_KEY], $states[1]->data->data->acceptKeys);
        self::assertSame(self::OTHER_SESSION_TOKEN, $states[1]->data->data->sessionToken);
        self::assertSame(SessionAck::SIGNED_IN, $states[1]->data->data->pendingAck);
        self::assertSame('done', $this->change()->phase);
        $this->dispatchName(' Grace ');
        $this->carry();
        self::assertSame(1, $this->personHops);
        $this->assertRenamedOnce('Grace');
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_MODERATION_CHECK));
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame($sessionsBefore, Database::sql('SELECT * FROM `hilos_session` ORDER BY `id`')->rows());
        self::assertSame($confirmationBefore, Database::sql('SELECT * FROM `hilos_step_up` ORDER BY `id`')->rows());
        self::assertSame('done', $this->change()->phase);
    }

    /** Only the current attempt may leave the shared queue and reach the owner. */
    public function testApprovalIsCorrelatedAndWritesNameAndJournalExactlyOnce(): void
    {
        $this->dispatchName('  Grace  ');
        $check = $this->check();
        self::assertSame('Grace', $check->text);
        self::assertSame(self::USER_ID, $check->userId);
        self::assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $check->verdictTo);
        $this->assertUnchanged();
        $this->assertChecking([true]);
        $this->dispatchName('Another');
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame($check->requestId, $this->check()->requestId);
        self::assertSame($check->deadlineAt, $this->check()->deadlineAt);
        $this->verdict(true, ModerationReason::OK, requestId: 'wrong-attempt');
        $this->verdict(true, ModerationReason::OK, kind: 'unknown-kind');
        self::assertSame($check->requestId, $this->check()->requestId);
        $this->assertUnchanged();
        $this->assertChecking([]);

        $this->verdict(true, ModerationReason::OK);
        self::assertNull($this->findCheck());
        $this->assertChecking([false]);
        $this->assertRenamedOnce('Grace');
        $this->assertSessionIdentity('Grace', [self::ACCEPT_KEY, self::OTHER_ACCEPT_KEY]);
        $this->verdict(true, ModerationReason::OK, requestId: $check->requestId);
        $this->assertRenamedOnce('Grace');
        self::assertSame(1, $this->personHops);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** The client's extra fields cannot choose the person, verdict recipient or approval. */
    public function testBrowserCannotChooseAuthority(): void
    {
        $this->inNameLibrary(fn () => $this->dispatcher->dispatchAction(new WebSocketActionSignalDTO(
            self::ACCEPT_KEY,
            HilosSignalConstants::PROFILE_NAME_SET,
            ['name' => 'Grace', 'userId' => self::OTHER_USER_ID, 'verdictTo' => 'another-agent', 'allow' => true],
            'authority-request',
        ), SignalSource::WEBSOCKET));

        self::assertSame(self::USER_ID, $this->check()->userId);
        self::assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $this->check()->verdictTo);
        $this->assertUnchanged();
        $this->verdict(true, ModerationReason::OK);
        $this->assertRenamedOnce('Grace');
        self::assertSame('Other', Hilos::$db->users[self::OTHER_USER_ID]->name);
    }

    /** The owner's no-op covers two browser sessions whose identical requests were both checked. */
    public function testTwoApprovalsOfTheSameNameWriteOneJournalRow(): void
    {
        $this->confirmStepUp(StepUpOperationKey::CHANGE_NAME, self::OTHER_SESSION_TOKEN);
        $this->dispatchName('Grace');
        $this->dispatchName('Grace', self::OTHER_ACCEPT_KEY);
        $other = $this->findCheck(self::OTHER_ACCEPT_KEY);
        self::assertNotNull($other);
        $this->verdict(true, ModerationReason::OK);
        $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(
            new AgentSignalData(new ModerationVerdictSignalData(
                self::OTHER_ACCEPT_KEY, ModerationKindKey::PROFILE_NAME, $other->requestId, true, ModerationReason::OK,
            )), 'test', HilosSignalConstants::HILOS_MODERATION_VERDICT,
        ));
        $this->carry();

        self::assertSame(2, $this->personHops);
        $this->assertRenamedOnce('Grace');
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
    }

    /** Administrator renames keep their existing path and never open the own-name check. */
    public function testAdministratorRenameDoesNotOpenCheck(): void
    {
        $this->expectedAuthor = self::ADMIN_USER_ID;
        $this->expectedAction = HilosSignalConstants::HILOS_USER_UPDATE;
        $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(new AgentSignalData(new AdminRenameSignalData(
            userId: self::USER_ID,
            name: 'Grace',
            replySignal: HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
            acceptKey: self::ADMIN_ACCEPT_KEY,
            requestId: null,
            action: HilosSignalConstants::HILOS_USER_UPDATE,
            successMessage: null,
            adminUserId: self::ADMIN_USER_ID,
        )), 'test', HilosSignalConstants::HILOS_USER_ADMIN_RENAME));
        $this->carry();

        self::assertSame('Grace', $this->storedName());
        self::assertSame([['user_id' => self::USER_ID, 'renamed_by_user_id' => self::ADMIN_USER_ID,
            'old_name' => 'Person', 'new_name' => 'Grace']], $this->journal());
        self::assertNull($this->findCheck());
        $this->assertChecking([]);
        self::assertCount(1, $this->take(HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
    }

    /** A journal write failure rolls back the name and releases no update announcement. */
    public function testJournalFailureRollsBackTheOwnerNameWrite(): void
    {
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        Database::sqlRun('DROP TABLE `hilos_user_rename`');
        try {
            $this->verdict(true, ModerationReason::OK);
        } finally {
            Database::sqlRun((string)file_get_contents(
                dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_user_rename.sql',
            ));
        }

        $this->assertError(SignalConstants::ACTION_FAILED_REASON);
        self::assertSame('Person', $this->storedName());
        self::assertSame('Person', Hilos::$db->users[self::USER_ID]->name);
        self::assertSame([], $this->journal());
        self::assertSame([], $this->nameLibrary->renamed);
        self::assertSame([], $this->take(SignalConstants::DB_SYNC_UPDATED));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** @return iterable<string, array{string, bool}> Every content reason and unavailable fallback */
    public static function refusals(): iterable
    {
        foreach (['insult', 'threat', 'hate_speech', 'sexual', 'spam', 'impersonation'] as $reason) {
            yield $reason => [$reason, true];
        }
        yield 'unavailable' => [ModerationReason::SERVICE_UNAVAILABLE, false];
        yield 'unknown' => ['provider-diagnostic', false];
    }

    /**
     * @param string $reason Moderation reason
     * @param bool $notify Whether a content notice is owed
     */
    #[DataProvider('refusals')]
    public function testRefusalsUseSafeSentencesAndOnlyContentNotifies(string $reason, bool $notify): void
    {
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->verdict(false, $reason);
        $this->assertChecking([false]);
        $message = ProfileNameRefusal::forReason($reason);
        $this->assertError($message);
        $this->assertUnchanged();
        $notices = $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT);
        self::assertCount($notify ? 1 : 0, $notices);
        if ($notify) {
            self::assertInstanceOf(AgentSignalData::class, $notices[0]->data);
            $notice = $notices[0]->data->data;
            self::assertInstanceOf(NotificationEmitSignalData::class, $notice);
            self::assertSame(self::USER_ID, $notice->userId);
            self::assertSame(UserNotificationType::NAME_REJECTED, $notice->type);
            self::assertSame('Your new name was not accepted', $notice->title);
            self::assertSame(NotificationSeverity::WARNING, $notice->severity);
            self::assertSame($message, $notice->body);
            self::assertSame(['reason' => $message, 'newName' => 'Grace'], $notice->data);
        }
    }

    /** A notice failure cannot take the action refusal from the requesting tab. */
    public function testNotificationFailureStillAnswersTheTab(): void
    {
        Hilos::$notify = new ProfileNameFailingNotifier();
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->verdict(false, 'insult');
        $this->assertChecking([false]);
        $this->assertError(ProfileNameRefusal::forReason('insult'));
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{mixed, string}> Invalid inputs and their public refusal */
    public static function invalidNames(): iterable
    {
        yield 'empty' => ['  ', 'User name cannot be empty'];
        yield 'short' => [' x ', 'User name is too short'];
        yield 'long' => [str_repeat('я', UserActions::NAME_MAX_LENGTH + 1), 'User name exceeds maximum length of 64 characters'];
        yield 'wrong type' => [42, 'Action could not be completed'];
    }

    /**
     * @param mixed $name Invalid input
     * @param string $message Expected server sentence
     */
    #[DataProvider('invalidNames')]
    public function testInvalidNameOpensNothing(mixed $name, string $message): void
    {
        $this->dispatchName($name);
        if (is_string($name)) {
            $this->assertError($message);
        } else {
            self::assertCount(1, $this->take(SignalConstants::ACTION_ERROR));
        }
        self::assertNull($this->findCheck());
        $this->assertChecking([]);
        $this->assertUnchanged();
    }

    /** Length uses characters, shares the owner policy, and accepts both inclusive boundaries. */
    public function testTrimAndInclusiveLengthBoundaries(): void
    {
        foreach ([str_repeat('я', UserActions::NAME_MIN_LENGTH), str_repeat('я', UserActions::NAME_MAX_LENGTH)] as $name) {
            $this->dispatchName('  ' . $name . '  ');
            self::assertSame($name, $this->check()->text);
            $this->verdict(true, ModerationReason::OK);
            self::assertSame($name, $this->storedName());
        }
        self::assertCount(2, $this->journal());
        self::assertCount(2, $this->nameLibrary->renamed);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
    }

    /** Equal names bypass moderation and owner writes, even when a moderator is enabled. */
    public function testEqualNameDoesNotOpenCheck(): void
    {
        $this->dispatchName(' Person ');
        self::assertNull($this->findCheck());
        $this->assertChecking([]);
        $this->assertUnchanged();
        self::assertSame('done', $this->change()->phase);
        $states = $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        self::assertCount(1, $states);
        self::assertSame('done', $states[0]->data->data->change['phase']);
    }

    /** Authentication and confirmation still close the action at real dispatch. */
    public function testAnonymousAndUnconfirmedSubmitsAreRefused(): void
    {
        $this->dispatchName('Grace', self::ANONYMOUS_ACCEPT_KEY);
        self::assertCount(1, $this->take(SignalConstants::ACTION_ERROR));
        $this->dispatchName('Grace', self::OTHER_ACCEPT_KEY);
        $this->assertError(StepUpMessages::EXPIRED);
        self::assertNull($this->findCheck());
        self::assertNull($this->findCheck(self::OTHER_ACCEPT_KEY));
        $this->assertUnchanged();
    }

    /** A confirmation for some other operation cannot authorize changing the name. */
    public function testConfirmationIsSpecificallyChangeName(): void
    {
        $this->confirmStepUp(StepUpOperationKey::CHANGE_EMAIL, self::OTHER_SESSION_TOKEN);
        $this->dispatchName('Grace', self::OTHER_ACCEPT_KEY);
        $this->assertError(StepUpMessages::EXPIRED);
        $this->assertUnchanged();
    }

    /** The dispatcher checks standing before it starts the command. */
    public function testFrozenStandingRefusesBeforeOpeningCheck(): void
    {
        ProfileNameFrozenHilos::initBrowser(new ProfileNameTestBrowser());
        Database::sqlRun('INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [self::USER_ID, LegalDocument::TERMS->value, 'first', '2026-01-01 00:00:00']);
        AccountStandingResolver::forgetAll();
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));
        $this->dispatchName('Grace');
        self::assertCount(1, $this->take(SignalConstants::ACTION_ERROR));
        self::assertNull($this->findCheck());
        $this->assertUnchanged();
    }

    /** An existing confirmation does not permit working on a name under impersonation. */
    public function testImpersonationRefusesBeforeOpeningCheck(): void
    {
        Database::sqlRun('UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::ADMIN_USER_ID, self::SESSION_TOKEN]);
        $this->dispatchName('Grace');
        $this->assertError(StepUpMessages::IMPERSONATED);
        self::assertNull($this->findCheck());
        $this->assertUnchanged();
    }

    /** Even an approval delivered at the deadline is unavailable. */
    public function testDeadlineOverridesApproval(): void
    {
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->expireNameCheck();
        $this->verdict(true, ModerationReason::OK);
        $this->assertUnavailable();
    }

    /** The library tick settles a stopped moderator with PROFILE_PHOTO disabled. */
    public function testSweepRunsWithoutProfilePhotoAndEndsExpiredNameChecks(): void
    {
        self::assertFalse(Hilos::hasFeature(HilosFeature::PROFILE_PHOTO));
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->expireNameCheck();
        $this->inNameLibrary(fn () => $this->nameLibrary->onTick());
        $this->assertUnavailable();
    }

    /** Stale replicas apply neither permission nor content refusal before their deadline. */
    public function testStaleAttemptWaitsAndThenFreshApprovalApplies(): void
    {
        $this->dispatchName('Grace');
        $request = $this->check()->requestId;
        $this->assertChecking([true]);
        RtStaleness::mark(StateHilosProfileNameChange::RT_COLLECTION, [$this->change()->sessionTokenHash], microtime(true));
        $this->verdict(true, ModerationReason::OK);
        $this->verdict(false, 'insult');
        $this->inNameLibrary(fn () => $this->nameLibrary->onTick());
        self::assertSame($request, $this->check()->requestId);
        $this->assertChecking([]);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $this->assertUnchanged();
        RtStaleness::clear(StateHilosProfileNameChange::RT_COLLECTION, [$this->change()->sessionTokenHash]);
        $this->verdict(true, ModerationReason::OK);
        $this->assertRenamedOnce('Grace');
    }

    /** Closing or reusing an origin socket leaves its session attempt alive and sends it no legacy frames. */
    public function testClosedAndReusedConnectionsDoNotCancelTheirSessionsAttempts(): void
    {
        $this->confirmStepUp(StepUpOperationKey::CHANGE_NAME, self::OTHER_SESSION_TOKEN);
        $this->dispatchName('Grace');
        $request = $this->check()->requestId;
        $this->dispatchName('Other name', self::OTHER_ACCEPT_KEY);
        $this->assertChecking([true, true]);
        $connections = Hilos::$rt->connectionsSource();
        self::assertNotNull($connections);
        $connections->remove(self::ACCEPT_KEY);
        $this->verdict(true, ModerationReason::OK, requestId: $request);
        $connections->remove(self::OTHER_ACCEPT_KEY);
        $connections->add(ProfileIntegrationConnection::create(self::OTHER_ACCEPT_KEY, self::OTHER_USER_ID, self::OTHER_SESSION_TOKEN));
        $this->inNameLibrary(fn () => $this->nameLibrary->onTick());
        self::assertNull($this->findCheck());
        self::assertNotNull($this->findCheck(self::OTHER_ACCEPT_KEY));
        $this->assertChecking([]);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame('Grace', $this->storedName());
        self::assertCount(1, $this->journal());
        self::assertSame(1, $this->personHops);
        self::assertCount(1, $this->nameLibrary->renamed);
        self::assertSame('checking', Hilos::$rt->hilosProfileNameChanges[
            ProtectedModeRuntime::hashSessionToken(self::OTHER_SESSION_TOKEN)]->phase);
    }

    /** The two kinds on one connection do not block or consume each other's attempt. */
    public function testNameAndPhotoAttemptsAreIndependent(): void
    {
        ProfileNameWithPhotoHilos::initBrowser(new ProfileNameTestBrowser());
        $photo = $this->inNameLibrary(fn () => Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_PHOTO, clientUploadId: 'photo-upload',
        ));
        $this->dispatchName('Grace');
        $request = $this->check()->requestId;
        $this->assertChecking([true]);
        $this->verdict(true, ModerationReason::OK);
        self::assertSame($photo->requestId,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO)?->requestId);
        $this->assertRenamedOnce('Grace');
        $this->dispatchName('Another');
        $this->verdict(true, ModerationReason::OK, requestId: $request);
        self::assertNotSame($request, $this->check()->requestId);
        $this->verdict(false, ModerationReason::SERVICE_UNAVAILABLE, kind: ModerationKindKey::PROFILE_PHOTO, requestId: $photo->requestId);
        self::assertNotNull($this->findCheck());
    }

    /** The name sweep leaves an expired photo attempt for its own workflow to settle. */
    public function testNameSweepDoesNotConsumePhotoChecks(): void
    {
        ProfileNameWithPhotoHilos::initBrowser(new ProfileNameTestBrowser());
        ProfileNameTestSettings::$timeoutSec = 0;
        $photo = $this->inNameLibrary(fn () => Hilos::$rt->hilosModerationChecks->actions->open(
            self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_PHOTO, clientUploadId: 'photo-upload',
        ));
        ProfileNameTestSettings::$timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->expireNameCheck();
        $this->inNameLibrary(fn () => $this->nameLibrary->sweepNameChecks());

        $this->assertUnavailable();
        self::assertSame($photo->requestId,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO)?->requestId);
    }

    /** @return iterable<string, array{bool, bool}> Both addressability guards and both disappearance forms */
    public static function disappearance(): iterable
    {
        yield 'deleted before verdict' => [false, false];
        yield 'merged before verdict' => [true, false];
        yield 'deleted after handoff' => [false, true];
        yield 'merged after handoff' => [true, true];
    }

    /**
     * @param bool $merged Keep a merge tombstone
     * @param bool $afterHandoff Exercise the owner guard
     */
    #[DataProvider('disappearance')]
    public function testGonePersonIsNeverRecreated(bool $merged, bool $afterHandoff): void
    {
        $this->dispatchName('Grace');
        $request = $this->check()->requestId;
        $this->assertChecking([true]);
        if ($afterHandoff) {
            $this->deliverVerdict(true, ModerationReason::OK, $request, ModerationKindKey::PROFILE_NAME);
            $this->carry(holdOwner: true);
            self::assertNotNull($this->heldRename);
        }
        if ($merged) {
            Hilos::$db->userMerges->actions->add(self::USER_ID, self::OTHER_USER_ID);
        } else {
            Hilos::$db->stepUps->actions->deleteForUser(self::USER_ID);
            foreach (Hilos::$db->sessions->whereColumnIs('userId', self::USER_ID) as $session) {
                $session->actions->delete();
            }
            Hilos::$db->users[self::USER_ID]->actions->delete();
        }
        if (!$afterHandoff) {
            $this->deliverVerdict(true, ModerationReason::OK, $request, ModerationKindKey::PROFILE_NAME);
        } else {
            $this->deliverToPerson($this->heldRename);
            $this->heldRename = null;
        }
        $this->carry();
        if ($merged) {
            $this->assertError('Failed to update user: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
            self::assertSame('refused', $this->change()->phase);
        } else {
            self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
            self::assertNull($this->change());
        }
        self::assertSame($afterHandoff ? 1 : 0, $this->personHops);
        self::assertSame([], $this->journal());
        if ($merged) {
            self::assertSame('Person', $this->storedName());
        } else {
            self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->users);
        }
    }

    /** Malformed or contradictory provider output cannot become a content refusal or permission. */
    public function testInvalidModelResponsesEndUnavailable(): void
    {
        foreach (['{}', '{"allow":true}', '{"allow":true,"reason":"insult"}',
            '{"allow":false,"reason":"unknown"}', '{"allow":false,"reason":"ok"}',
            '{"allow":false,"reason":"policy"}', '{"allow":false,"reason":"blocked"}', 'not json'] as $text) {
            $this->dispatchName('Grace');
            $this->assertChecking([true]);
            try {
                ModerationDecision::fromModelOutput($text, Hilos::moderationKinds()[ModerationKindKey::PROFILE_NAME]);
                self::fail('Malformed output must be refused by the strict parser');
            } catch (ValidationException) {
                $decision = ModerationDecision::unavailable();
            }
            $this->verdict($decision->allow, $decision->reason);
            $this->assertUnavailable();
        }
    }

    public function testReloadUsesTheSameAttemptAndSnapshotAfterTheOriginSocketCloses(): void
    {
        $this->dispatchName('Grace');
        $check = $this->check();
        $this->assertChecking([true]);
        $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        Hilos::$rt->connectionsSource()->remove(self::ACCEPT_KEY);
        Hilos::$rt->connectionsSource()->add(ProfileIntegrationConnection::create('reloaded-tab', self::USER_ID, self::SESSION_TOKEN));
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(headers: [], acceptKey: 'reloaded-tab', cookies: [],
                clientIp: null, sessionToken: self::SESSION_TOKEN), 'test', 'handshake',
        ));
        $states = $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        self::assertCount(1, $states);
        self::assertSame(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN), $states[0]->data->targetSessionTokenHash);
        self::assertSame($check->requestId, $states[0]->data->data->change['requestId']);
        self::assertSame($check->deadlineAt, $this->check()->deadlineAt);
        $this->take('handshake_response');
        $this->verdict(true, ModerationReason::OK);
        $this->assertRenamedOnce('Grace');
        $this->assertSessionIdentity('Grace', [self::OTHER_ACCEPT_KEY, 'reloaded-tab']);
        self::assertSame('done', $this->change()->phase);
        $this->assertChecking([]);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
    }

    public function testContinueBeforeItsSourceRowDoesNotLoseOrDuplicateTheAcceptedCheck(): void
    {
        $this->dispatchName('Grace');
        $begin = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN) {
                $begin = $signal;
                break;
            }
            $this->outbox[] = $signal;
        }
        self::assertNotNull($begin);
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onSignalAgent(
            $begin->data, 'users', HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN,
        ));
        $change = $this->change();
        $row = $change->toArray();
        RtSyncApplicator::applyDeleted(new RtSyncDeletedSignalData(
            StateHilosProfileNameChange::RT_COLLECTION, $change->sessionTokenHash, $row,
        ), false);
        $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(new AgentSignalData(new ProfileNameContinueSignalData(
            $change->sessionTokenHash, $change->requestId,
        )), 'holder', HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE));
        self::assertCount(0, Hilos::$rt->hilosModerationChecks);
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            StateHilosProfileNameChange::RT_COLLECTION, $change->sessionTokenHash, $row,
        ), false);
        $this->carry();
        self::assertSame($change->requestId, $this->check()->requestId);
        self::assertSame($change->deadlineAt, $this->check()->deadlineAt);
        self::assertCount(1, Hilos::$rt->hilosModerationChecks);
        $this->assertChecking([true]);
        $this->verdict(true, ModerationReason::OK);
        $this->assertRenamedOnce('Grace');
    }

    public function testPendingTabsJoinOneAttemptWhileAnotherBrowserHasItsOwn(): void
    {
        Hilos::$rt->connectionsSource()->add(ProfileIntegrationConnection::create('sibling-tab', self::USER_ID, self::SESSION_TOKEN));
        $this->confirmStepUp(StepUpOperationKey::CHANGE_NAME, self::OTHER_SESSION_TOKEN);
        $this->dispatchName('Grace');
        $first = $this->check();
        $this->dispatchName('Another', 'sibling-tab');
        $this->carry();
        self::assertSame($first->requestId, $this->change()->requestId);
        self::assertSame('Grace', $this->change()->name);
        self::assertSame($first->deadlineAt, $this->change()->deadlineAt);
        self::assertCount(1, Hilos::$rt->hilosProfileNameChanges);
        $this->dispatchName('Independent', self::OTHER_ACCEPT_KEY);
        $this->carry();
        self::assertCount(2, Hilos::$rt->hilosProfileNameChanges);
        self::assertNotSame($first->requestId, $this->findCheck(self::OTHER_ACCEPT_KEY)->requestId);
        self::assertNull($this->findCheck('sibling-tab'));
        $this->assertUnchanged();
    }

    public function testApplyingWaitsForOwnerAndRepeatedAdvancesAndWakeupsDoNotRepeatTheHandoff(): void
    {
        $this->dispatchName('Grace');
        $check = $this->check();
        $this->deliverVerdict(true, ModerationReason::OK, $check->requestId, ModerationKindKey::PROFILE_NAME);
        $this->carry(holdOwner: true);
        self::assertSame('applying', $this->change()->phase);
        self::assertNull($this->change()->deadlineAt);
        self::assertSame('Person', $this->storedName());
        foreach ([1, 2] as $ignored) {
            $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(new AgentSignalData(new ProfileNameContinueSignalData(
                $this->change()->sessionTokenHash, $check->requestId,
            )), 'holder', HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE));
            $this->advanceName('applying', $check->requestId);
            $this->deliverVerdict(true, ModerationReason::OK, $check->requestId, ModerationKindKey::PROFILE_NAME);
        }
        $this->carry();
        self::assertSame(1, $this->personHops);
        self::assertNull($this->findCheck());
        $this->deliverToPerson($this->heldRename);
        $this->heldRename = null;
        $this->carry();
        self::assertSame('done', $this->change()->phase);
        $this->assertRenamedOnce('Grace');
    }

    public function testHolderDeadlineRefusesWithoutWaitingForAModeratorAndReclaimsTheQueue(): void
    {
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->expireNameCheck();
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onTick());
        $this->inNameLibrary(fn () => $this->nameLibrary->sweepNameChecks());
        $this->carry();
        self::assertSame('refused', $this->change()->phase);
        $this->assertUnavailable();
    }

    public function testDuplicateOwnerReplyCannotRepeatTheHookOrRefuseANewAttempt(): void
    {
        $this->dispatchName('Grace');
        $this->verdict(true, ModerationReason::OK);
        $done = $this->lastRenameDone;
        self::assertNotNull($done);
        $this->assertSessionIdentity('Grace', [self::ACCEPT_KEY, self::OTHER_ACCEPT_KEY]);
        $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(new AgentSignalData($done), 'owner',
            HilosSignalConstants::HILOS_USER_RENAME_DONE));
        $this->carry();
        $this->assertRenamedOnce('Grace');
        $this->dispatchName('New name');
        $this->carry();
        $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(new AgentSignalData($done), 'owner',
            HilosSignalConstants::HILOS_USER_RENAME_DONE));
        $this->carry();
        self::assertSame('checking', $this->change()->phase);
        self::assertCount(1, $this->nameLibrary->renamed);
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        self::assertSame([], $this->take('handshake_response'));
    }

    public function testLateRepliesCannotRefuseOrCompleteTheNextAttempt(): void
    {
        $this->dispatchName('Grace');
        $old = $this->check()->requestId;
        $this->verdict(false, 'insult');
        self::assertSame('refused', $this->change()->phase);
        $this->take(SignalConstants::ACTION_ERROR);
        $this->dispatchName('New name');
        $current = $this->check()->requestId;
        self::assertNotSame($old, $current);
        $this->advanceName('refused', $old, 'An obsolete refusal.');
        $this->advanceName('done', $old);
        $this->deliverVerdict(false, 'insult', $old, ModerationKindKey::PROFILE_NAME);
        $this->carry();
        self::assertSame($current, $this->change()->requestId);
        self::assertSame('checking', $this->change()->phase);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        $this->assertUnchanged();
    }

    public function testExpiredSessionIsClearedAndItsOrphanQueueIsReclaimed(): void
    {
        $this->dispatchName('Grace');
        $this->check();
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), function (): void {
            Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->actions->expire();
            $this->holder->onTick();
        });
        $this->carry();
        self::assertNull($this->change(), 'The committed expiry source fact clears the attempt before queue maintenance');
        $this->inNameLibrary(fn () => $this->nameLibrary->sweepNameChecks());
        $this->carry();
        self::assertNull($this->change());
        self::assertNull($this->findCheck());
        $states = $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        self::assertSame(['userId' => null, 'change' => null], $states[array_key_last($states)]->data->data->toArray());
        $this->assertUnchanged();
    }

    /** The holder observes committed session facts even when no explicit auth command is running. */
    #[DataProvider('sessionLossFacts')]
    public function testSessionSourceFactsClearTheAttemptAndItsOrphanQueue(string $fact): void
    {
        $this->confirmStepUp(StepUpOperationKey::CHANGE_NAME, self::OTHER_SESSION_TOKEN);
        $this->dispatchName('Grace');
        $this->dispatchName('Other browser', self::OTHER_ACCEPT_KEY);
        $this->carry();
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), static function () use ($fact): void {
            $session = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN);
            match ($fact) {
                'person' => $session->actions->bindUser(self::ADMIN_USER_ID),
                'takeover' => $session->actions->setImpersonator(self::ADMIN_USER_ID),
                'rotation' => $session->actions->rotateTokenAndUnbindUser(str_repeat('e', 32)),
                'delete' => $session->actions->delete(),
            };
        });
        $this->carry();
        self::assertNull($this->change());
        self::assertNotNull(Hilos::$rt->hilosProfileNameChanges[ProtectedModeRuntime::hashSessionToken(self::OTHER_SESSION_TOKEN)]);
        $this->inNameLibrary(fn () => $this->nameLibrary->sweepNameChecks());
        $this->carry();
        self::assertNull($this->findCheck());
        self::assertNotNull($this->findCheck(self::OTHER_ACCEPT_KEY));
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{string}> Existing owner writes that end an attempt's identity */
    public static function sessionLossFacts(): iterable
    {
        foreach (['person', 'takeover', 'rotation', 'delete'] as $fact) {
            yield $fact => [$fact];
        }
    }

    /** Retained results must not turn an idle holder tick into a session query per browser. */
    public function testTerminalResultsDoNotPollSessionsOnIdleTicks(): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), function (): void {
            for ($index = 0; $index < 40; $index++) {
                $token = hash('sha256', 'retained-name-result-' . $index);
                self::seedSession($token, self::USER_ID, '2026-10-01 10:00:00', null);
                $hash = ProtectedModeRuntime::hashSessionToken($token);
                Hilos::$rt->hilosProfileNameChanges->actions->open($hash, self::USER_ID, str_repeat('a', 32),
                    self::ACCEPT_KEY, 'Person', TimeHelper::nowMs(), null);
                if ($index % 2 === 0) {
                    Hilos::$rt->hilosProfileNameChanges[$hash]->actions->complete();
                } else {
                    Hilos::$rt->hilosProfileNameChanges[$hash]->actions->refuse('This name was not accepted.');
                }
            }
        });
        $this->carry();
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onTick());
        for ($pass = 0; $pass < 3; $pass++) {
            new ReflectionProperty(AbstractSessionsLibraryAgent::class, 'lastProfileNameSweepAt')->setValue($this->holder, 0.0);
            $before = (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
            ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onTick());
            $after = (int)Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
            self::assertSame($before, $after, 'Retained name results must cause no idle SQL reads');
            self::assertCount(40, Hilos::$rt->hilosProfileNameChanges);
        }
    }

    /** The browser action passes with a live proof; the real BEGIN is held until that proof expires. */
    public function testProofExpiringBetweenActionAndBeginAnswersTheOriginWithoutAdmission(): void
    {
        $this->dispatchName('Grace');
        $begin = $this->holdNameBegin();
        Hilos::$db->stepUps->actions->confirm(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID, StepUpOperationKey::CHANGE_NAME, date('Y-m-d H:i:s', time() - 1));
        $this->deliverNameBegin($begin);
        $errors = $this->take(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertSame(self::ACCEPT_KEY, $errors[0]->data->targetAcceptKey);
        self::assertSame(['action' => HilosSignalConstants::PROFILE_NAME_SET, 'reason' => StepUpMessages::EXPIRED],
            $errors[0]->data->data->toArray());
        self::assertCount(0, Hilos::$rt->hilosProfileNameChanges);
        self::assertCount(0, Hilos::$rt->hilosModerationChecks);
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_MODERATION_CHECK));
        $this->assertUnchanged();
    }

    /** Known refusals of the same live requester must answer even before there is an attempt to publish. */
    #[DataProvider('lateAdmissionRefusals')]
    public function testKnownLateAdmissionRefusalsAnswerWithoutOpeningAnAttempt(string $refusal): void
    {
        $this->dispatchName('Grace');
        $begin = $this->holdNameBegin();
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), static function () use ($refusal): void {
            if ($refusal === 'expiry') {
                Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->actions->expire();
            } else {
                Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->actions->setImpersonator(self::ADMIN_USER_ID);
            }
        });
        $this->deliverNameBegin($begin);
        $errors = $this->take(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertSame(self::ACCEPT_KEY, $errors[0]->data->targetAcceptKey);
        self::assertSame(HilosSignalConstants::PROFILE_NAME_SET, $errors[0]->data->data->action);
        self::assertNotEmpty($errors[0]->data->data->reason);
        self::assertCount(0, Hilos::$rt->hilosProfileNameChanges);
        self::assertCount(0, Hilos::$rt->hilosModerationChecks);
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE));
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{string}> Late refusals while the session still names the same person */
    public static function lateAdmissionRefusals(): iterable
    {
        yield 'expired session' => ['expiry'];
        yield 'takeover' => ['takeover'];
    }

    public function testHeldBeginWithTheSameLiveProofStillRenamesOnce(): void
    {
        $this->dispatchName('Grace');
        $this->deliverNameBegin($this->holdNameBegin());
        $this->carry();
        $this->verdict(true, ModerationReason::OK);
        self::assertSame('done', $this->change()->phase);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        $this->assertRenamedOnce('Grace');
    }

    public function testRefusedDuplicateBeginDoesNotCancelTheAlreadyAcceptedAttempt(): void
    {
        $this->dispatchName('Grace');
        $requestId = $this->check()->requestId;
        $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        $this->dispatchName('Another');
        $begin = $this->holdNameBegin();
        Hilos::$db->stepUps->actions->confirm(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID, StepUpOperationKey::CHANGE_NAME, date('Y-m-d H:i:s', time() - 1));
        $this->deliverNameBegin($begin);
        self::assertCount(1, $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame($requestId, $this->change()->requestId);
        self::assertSame('checking', $this->change()->phase);
        self::assertSame($requestId, $this->check()->requestId);
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE));
        $this->assertUnchanged();
    }

    /** A normal touch and closed origin keep a terminal result; the existing bounded sweep owns physical expiry. */
    public function testTerminalResultSurvivesTouchAndDisconnectUntilTheEndedSessionSweep(): void
    {
        $this->dispatchName('Person');
        $this->carry();
        $requestId = $this->change()->requestId;
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), function (): void {
            Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->actions->touch();
        });
        $this->carry();
        Hilos::$rt->connectionsSource()->remove(self::ACCEPT_KEY);
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onTick());
        self::assertSame($requestId, $this->change()->requestId);
        // A wall-clock expiry has no DB write: age the fixture row without announcing a source event.
        Database::sqlRun('UPDATE `hilos_session` SET `expires_at` = ? WHERE `token` = ?',
            [date('Y-m-d H:i:s', time() - 1), self::SESSION_TOKEN]);
        Hilos::$db->forgetLoadedDataForTest();
        new ReflectionProperty(AbstractSessionsLibraryAgent::class, 'sessionSweepBacklog')->setValue($this->holder, true);
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onTick());
        $this->carry();
        self::assertNull($this->change());
        self::assertNull(Hilos::$db->sessions->findByToken(self::SESSION_TOKEN));
        $this->assertUnchanged();
    }

    /** A delayed refusal belongs only to the still-current socket of the original session and person. */
    #[DataProvider('lateBeginOrigins')]
    public function testLateBeginDoesNotRefuseAMissingReusedOrStaleOrigin(string $origin): void
    {
        $this->dispatchName('Grace');
        $begin = $this->holdNameBegin();
        Hilos::$db->stepUps->actions->confirm(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
            self::USER_ID, StepUpOperationKey::CHANGE_NAME, date('Y-m-d H:i:s', time() - 1));
        Hilos::$rt->connectionsSource()->remove(self::ACCEPT_KEY);
        if ($origin !== 'missing') {
            Hilos::$rt->connectionsSource()->add(ProfileIntegrationConnection::create(self::ACCEPT_KEY,
                $origin === 'person' ? self::ADMIN_USER_ID : self::USER_ID,
                $origin === 'session' ? self::OTHER_SESSION_TOKEN : self::SESSION_TOKEN));
        }
        if ($origin === 'stale') {
            RtStaleness::mark(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY], microtime(true));
        }
        $sessions = Database::sql('SELECT * FROM `hilos_session` ORDER BY `id`')->rows();
        $this->deliverNameBegin($begin);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE));
        self::assertCount(0, Hilos::$rt->hilosProfileNameChanges);
        self::assertCount(0, Hilos::$rt->hilosModerationChecks);
        self::assertSame($sessions, Database::sql('SELECT * FROM `hilos_session` ORDER BY `id`')->rows());
        $this->assertUnchanged();
    }

    /** @return iterable<string, array{string}> Lost or reassigned requester addresses */
    public static function lateBeginOrigins(): iterable
    {
        foreach (['missing', 'person', 'session', 'stale'] as $origin) {
            yield $origin => [$origin];
        }
    }

    public function testSignoutClearsTheAttemptAndLeavesAnotherBrowsersAttemptAlone(): void
    {
        $this->confirmStepUp(StepUpOperationKey::CHANGE_NAME, self::OTHER_SESSION_TOKEN);
        $this->dispatchName('Grace');
        $this->dispatchName('Other browser', self::OTHER_ACCEPT_KEY);
        $this->carry();
        $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onAgentAction(
            self::ACCEPT_KEY, HilosSignalConstants::HILOS_LOGOUT, new LogoutActionDTO(),
        ));
        $this->inNameLibrary(fn () => $this->nameLibrary->sweepNameChecks());
        $this->carry();
        self::assertNull($this->change());
        self::assertNotNull(Hilos::$rt->hilosProfileNameChanges[ProtectedModeRuntime::hashSessionToken(self::OTHER_SESSION_TOKEN)]);
        self::assertNull($this->findCheck());
        self::assertNotNull($this->findCheck(self::OTHER_ACCEPT_KEY));
        $states = $this->take(HilosSignalConstants::HILOS_PROFILE_NAME_STATE);
        self::assertCount(1, $states);
        self::assertSame(['userId' => null, 'change' => null], $states[0]->data->data->toArray());
        $this->assertUnchanged();
    }

    public function testEndingATakeoverClearsTheOldPersonsAttempt(): void
    {
        $this->dispatchName('Grace');
        $request = $this->check()->requestId;
        Database::sqlRun('UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::ADMIN_USER_ID, self::SESSION_TOKEN]);
        Hilos::$db->forgetLoadedDataForTest();
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onAgentAction(
            self::ACCEPT_KEY, HilosSignalConstants::HILOS_IMPERSONATE_STOP, new ImpersonateStopActionDTO(),
        ));
        $this->deliverVerdict(false, 'insult', $request, ModerationKindKey::PROFILE_NAME);
        $this->carry();
        self::assertNull($this->change());
        self::assertNull($this->findCheck());
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $this->assertUnchanged();
    }

    /**
     * @param string $phase Reported phase
     * @param string $requestId Operation id
     * @param ?string $refusal Refusal words
     */
    private function advanceName(string $phase, string $requestId, ?string $refusal = null): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onSignalAgent(
            new AgentSignalData(new ProfileNameAdvanceSignalData(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                $requestId, $phase, $refusal)), 'users', HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE,
        ));
    }

    /** @return SignalDTO The actual queued BEGIN, before the holder accepts it */
    private function holdNameBegin(): SignalDTO
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN) {
                return $signal;
            }
            $this->outbox[] = $signal;
        }
        self::fail('The successful browser action must queue BEGIN');
    }

    /** @param SignalDTO $begin Held request sent by the browser action */
    private function deliverNameBegin(SignalDTO $begin): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()), fn () => $this->holder->onSignalAgent(
            $begin->data, 'users', HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN,
        ));
    }

    /**
     * @param mixed $name Wire name input
     * @param string $acceptKey Submitting connection
     */
    private function dispatchName(mixed $name, string $acceptKey = self::ACCEPT_KEY): void
    {
        $this->inNameLibrary(fn () => $this->dispatcher->dispatchAction(
            new WebSocketActionSignalDTO(
                $acceptKey, HilosSignalConstants::PROFILE_NAME_SET, [ProfileNameSetActionDTO::name => $name], 'profile-name-request',
            ),
            SignalSource::WEBSOCKET,
        ));
    }

    /**
     * @param bool $allow Permission
     * @param string $reason Reason
     * @param ?string $requestId Attempt
     * @param string $kind Kind
     */
    private function verdict(bool $allow, string $reason, ?string $requestId = null, string $kind = ModerationKindKey::PROFILE_NAME): void
    {
        $this->deliverVerdict($allow, $reason, $requestId ?? $this->check()->requestId, $kind);
        $this->carry();
    }

    /**
     * @param bool $allow Permission
     * @param string $reason Reason
     * @param string $requestId Attempt
     * @param string $kind Kind
     */
    private function deliverVerdict(bool $allow, string $reason, string $requestId, string $kind): void
    {
        $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent(
            new AgentSignalData(new ModerationVerdictSignalData(self::ACCEPT_KEY, $kind, $requestId, $allow, $reason)),
            'test', HilosSignalConstants::HILOS_MODERATION_VERDICT,
        ));
    }

    /**
     * Carries the real holder, source, owner and completion frames, retaining browser frames.
     *
     * @param bool $holdOwner Stop before executing the person owner's ask
     */
    private function carry(bool $holdOwner = false): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($name === HilosSignalConstants::HILOS_USER_RENAME) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameSignalData::class, $signal->data->data);
                self::assertSame($this->expectedAuthor, $signal->data->data->renamedByUserId);
                self::assertSame($this->expectedAction, $signal->data->data->action);
                if ($this->expectedAction === HilosSignalConstants::PROFILE_NAME_SET) {
                    $attempt = null;
                    foreach (Hilos::$rt->hilosProfileNameChanges as $change) {
                        if ($change->originAcceptKey === $signal->data->data->acceptKey) {
                            $attempt = $change;
                        }
                    }
                    self::assertNotNull($attempt);
                    self::assertSame($attempt->requestId, $signal->data->data->requestId);
                } else {
                    self::assertNull($signal->data->data->requestId);
                }
                self::assertSame($this->expectedAuthor === self::USER_ID ? null : HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                    $signal->data->data->answerSignal);
                $this->personHops++;
                if ($holdOwner) {
                    self::assertNull($this->heldRename);
                    $this->heldRename = $signal;

                    return;
                }
                $this->deliverToPerson($signal);
            } elseif ($name === HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE) {
                $this->outbox[] = $signal;
                ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()),
                    fn () => $this->holder->onSignalAgent($signal->data, 'test', $name));
            } elseif ($name === HilosSignalConstants::HILOS_SESSION_STATE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(SessionStateSignalData::class, $signal->data->data);
                $this->inNameLibrary(fn () => $this->nameLibrary->publishIdentity($signal->data->data));
                $this->outbox[] = $signal;
            } elseif (in_array($name, [HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN, HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE], true)) {
                ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()),
                    fn () => $this->holder->onSignalAgent($signal->data, 'test', $name));
            } elseif ($name === HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE) {
                $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent($signal->data, 'test', $name));
            } elseif ($name === SignalConstants::RT_SYNC_CREATED) {
                $this->inNameLibrary(fn () => $this->nameLibrary->onSignalRtSyncCreated($signal->data, 'test', $name));
            } elseif ($name === SignalConstants::RT_SYNC_UPDATED) {
                $this->inNameLibrary(fn () => $this->nameLibrary->onSignalRtSyncUpdated($signal->data, 'test', $name));
            } elseif ($name === SignalConstants::DB_SYNC_UPDATED) {
                ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()),
                    fn () => $this->holder->onSignalDbSyncUpdated($signal->data, 'test', $name));
            } elseif ($name === SignalConstants::DB_SYNC_DELETED) {
                ExecutionContext::run(new ExecutionFrame(agentId: $this->holder->getId()),
                    fn () => $this->holder->onSignalDbSyncDeleted($signal->data, 'test', $name));
            } elseif ($name === HilosSignalConstants::HILOS_USER_RENAME_DONE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameDoneSignalData::class, $signal->data->data);
                $this->lastRenameDone = $signal->data->data;
                $this->inNameLibrary(fn () => $this->nameLibrary->onSignalAgent($signal->data, 'test', $name));
            } else {
                $this->outbox[] = $signal;
            }
        }
    }

    /**
     * @param string $signalName Name to consume
     * @return list<SignalDTO> Matching frames in order
     */
    private function take(string $signalName): array
    {
        $this->carry();
        $matches = array_values(array_filter($this->outbox, static fn (SignalDTO $s): bool => $s->signalName->getName() === $signalName));
        $this->outbox = array_values(array_filter($this->outbox, static fn (SignalDTO $s): bool => $s->signalName->getName() !== $signalName));
        return $matches;
    }

    /** @param list<bool> $states Name checking flags, in order */
    private function assertChecking(array $states): void
    {
        $frames = $this->take(HilosSignalConstants::HILOS_MODERATION_CHECK);
        self::assertSame($states, array_map(static function (SignalDTO $s): bool {
            self::assertInstanceOf(WebSocketSignalData::class, $s->data);
            self::assertInstanceOf(ModerationCheckSignalData::class, $s->data->data);
            self::assertSame(ModerationKindKey::PROFILE_NAME, $s->data->data->kind);
            return $s->data->data->checking;
        }, $frames));
    }

    /** @param string $message Safe failure sentence */
    private function assertError(string $message): void
    {
        $errors = $this->take(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
        self::assertInstanceOf(PageActionErrorSignalData::class, $errors[0]->data->data);
        self::assertSame(HilosSignalConstants::PROFILE_NAME_SET, $errors[0]->data->data->action);
        self::assertSame($message, $errors[0]->data->data->reason);
    }

    /** The previous name remains published and no name or journal write reached the owner. */
    private function assertUnchanged(): void
    {
        self::assertSame('Person', $this->storedName());
        self::assertSame([], $this->journal());
        self::assertSame(0, $this->personHops);
        self::assertSame([], $this->nameLibrary->renamed);
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
    }

    /**
     * @param string $name Committed name sent through the framework session identity builder
     * @param list<string> $acceptKeys Only this person's open tabs
     */
    private function assertSessionIdentity(string $name, array $acceptKeys): void
    {
        $restates = $this->take(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE);
        self::assertCount(1, $restates);
        self::assertSame(self::USER_ID, $restates[0]->data->data->userId);
        $responses = $this->take('handshake_response');
        self::assertCount(count($acceptKeys), $responses);
        $actualKeys = [];
        foreach ($responses as $response) {
            self::assertInstanceOf(WebSocketSignalData::class, $response->data);
            self::assertInstanceOf(HandshakeResponseSignalData::class, $response->data->data);
            self::assertSame(self::USER_ID, $response->data->data->selfId);
            self::assertSame($name, $response->data->data->selfName);
            self::assertSame($name, $response->data->data->toArray()['entities']['currentUser']['name']);
            $actualKeys[] = $response->data->targetAcceptKey;
        }
        sort($actualKeys);
        sort($acceptKeys);
        self::assertSame($acceptKeys, $actualKeys);
    }

    /** @param string $name Name written together with the sole journal entry */
    private function assertRenamedOnce(string $name): void
    {
        self::assertSame($name, $this->storedName());
        self::assertSame([['user_id' => self::USER_ID, 'renamed_by_user_id' => self::USER_ID,
            'old_name' => 'Person', 'new_name' => $name]], $this->journal());
        self::assertCount(1, $this->nameLibrary->renamed);
        self::assertFalse(Database::hasOpenTransactions());
    }

    /** An unavailable check preserves the published name and sends no content notice. */
    private function assertUnavailable(): void
    {
        self::assertNull($this->findCheck());
        $this->assertChecking([false]);
        $this->assertError('Names cannot be checked right now.');
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $this->assertUnchanged();
    }

    /** @return string Independently read persisted name */
    private function storedName(): string
    {
        return Database::sql('SELECT `name` FROM `hilos_user` WHERE `id` = ?', [self::USER_ID])->rows()[0]['name'];
    }

    /** @return list<array<string, mixed>> Independently read persisted journal rows */
    private function journal(): array
    {
        return array_map(static fn (array $row): array => [
            'user_id' => (int)$row['user_id'],
            'renamed_by_user_id' => $row['renamed_by_user_id'] === null ? null : (int)$row['renamed_by_user_id'],
            'old_name' => $row['old_name'],
            'new_name' => $row['new_name'],
        ], Database::sql(
            'SELECT `user_id`, `renamed_by_user_id`, `old_name`, `new_name` FROM `hilos_user_rename` ORDER BY `id`',
        )->rows());
    }

    /** @return HilosModerationCheck Current name attempt */
    private function check(): HilosModerationCheck
    {
        $this->carry();
        return $this->findCheck() ?? self::fail('No name check');
    }

    /**
     * @param string $acceptKey Connection
     * @return ?HilosModerationCheck Current name attempt, if any
     */
    private function findCheck(string $acceptKey = self::ACCEPT_KEY): ?HilosModerationCheck
    {
        $this->carry();

        return Hilos::$rt->hilosModerationChecks->find($acceptKey, ModerationKindKey::PROFILE_NAME);
    }

    /** Moves the two current source deadlines into the past through the same sync boundary as another worker. */
    private function expireNameCheck(): void
    {
        $check = $this->check();
        $deadline = TimeHelper::nowMs() - 1;
        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            StateHilosProfileNameChange::RT_COLLECTION, $this->change()->sessionTokenHash,
            [StateHilosProfileNameChange::deadlineAt => $deadline],
        ), false);
        $row = $check->toArray();
        $row[StateHilosModerationCheck::deadlineAt] = $deadline;
        $this->inNameLibrary(fn () => $check->actions->forget());
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            StateHilosModerationCheck::RT_COLLECTION, $check->getId(), $row,
        ), false);
    }

    /** @return ?HilosProfileNameChange Current session's accepted operation */
    private function change(): ?HilosProfileNameChange
    {
        return Hilos::$rt->hilosProfileNameChanges[ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN)];
    }

    /**
     * @template T
     * @param callable(): T $step Library handler
     * @return T Handler result
     */
    private function inNameLibrary(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->nameLibrary->getId()), $step);
    }
}

/** Records the framework's completion hook without replacing its rename path. */
final class ProfileNameTestLibrary extends AbstractUsersLibraryAgent
{
    /** @var list<int> Journal ids delivered to the project after commit */
    public array $renamed = [];

    /** @param UserRename $rename Committed journal row */
    public function afterUserRenamed(UserRename $rename): void
    {
        self::assertCommitted();
        $this->renamed[] = $rename->id;
    }

    /** @param SessionStateSignalData $state Session frame delivered to the fixture's project receiver */
    public function publishIdentity(SessionStateSignalData $state): void
    {
        $identity = $this->handshakeIdentity($state);
        foreach ($state->acceptKeys as $acceptKey) {
            $this->sendHandshakeResponse('handshake_response', $acceptKey, $identity, $state);
        }
    }

    /** Runs the name sweep alone so its kind boundary is observable beside an expired photo. */
    public function sweepNameChecks(): void
    {
        new ProfileNameCommands($this, $this->stepUpCommands())->sweepChecks();
    }

    /** A hook observes an ended transaction; the case independently checks its persisted rows. */
    private static function assertCommitted(): void
    {
        if (Database::hasOpenTransactions()) {
            throw new ValidationException('The rename hook must follow commit');
        }
    }
}

/** Profile tabs and the shared queue; no photo runtime is required. */
final class ProfileNameTestRuntime extends ProfileIntegrationRtContext
{
    /** Mounts moderation in addition to the profile fixture. */
    public function configure(): void
    {
        $this->mountFeatureRuntime([new AuthFeature()]);
        parent::configure();
        new ModerationFeature()->mount($this);
    }
}

/** Agent routes computed from the same fixture facade as dispatch. */
final class ProfileNameTestRouter extends SignalRouter
{
    /** @return string Active fixture topology */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}

/** Identity comes from the fixture's actual connection, including its anonymous tab. */
final class ProfileNameTestBrowser extends BrowserContext
{
    /**
     * @param string $acceptKey Connection
     * @return ConnectionIdentity Settled identity
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(Hilos::$rt->connectionsSource()?->get($acceptKey)?->userId);
    }
}

/** Fixture profile settings with the real moderation, legal and impersonation fragments. */
final class ProfileNameTestSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Settings catalog */
    public static function getCatalog(): array
    {
        return array_replace(ProfileIntegrationSettingsCatalog::getCatalog(), ModerationSettingsCatalog::getCatalog(),
            LegalSettingsCatalog::getCatalog(), ImpersonationSettingsCatalog::getCatalog());
    }
}

/** Allows a zero deadline without waiting on wall time. */
final class ProfileNameTestSettings extends SettingsAccessor
{
    public static int $timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;

    /**
     * @param string $key Setting
     * @return mixed Scripted timeout or real setting
     */
    public function effectiveValueFor(string $key): mixed
    {
        return match ($key) {
            ModerationSettingsCatalog::CHECK_TIMEOUT_SEC => self::$timeoutSec,
            LegalSettings::REFUSAL_KEY => LegalSettings::REFUSAL_FREEZE,
            default => parent::effectiveValueFor($key),
        };
    }
}

/** A project whose own-name action needs no moderation queue. */
abstract class ProfileNameImmediateHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
    public const array AGENTS = [
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ProfileNameTestLibrary::class],
    ];
}

/** Text moderation without the profile photo feature. */
abstract class ProfileNameModeratedHilos extends ProfileNameImmediateHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::MODERATION];
}

/** Exposes both built-in kinds to exercise independent rows on the same connection. */
abstract class ProfileNameWithPhotoHilos extends ProfileNameModeratedHilos
{
    protected const array FEATURES = [HilosFeature::AUTH, HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO];
}

/** A project whose previously accepted terms have lapsed. */
abstract class ProfileNameFrozenHilos extends ProfileNameModeratedHilos
{
    protected const ?string LEGAL_CATALOG = ProfileNameTestLegalCatalog::class;
}

/** Two in-force substantive revisions, only the first accepted by the frozen case. */
final class ProfileNameTestLegalCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture legal revisions */
    public static function revisions(): array
    {
        return [LegalDocument::TERMS->value => [
            new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-01', []),
        ]];
    }
}

/** Fails at the notification door while the action's refusal still has to arrive. */
final class ProfileNameFailingNotifier extends HilosNotifier
{
    /** @param NotificationDraft $draft Unqueued notice */
    public function emit(NotificationDraft $draft): void
    {
        throw new ValidationException('The notification queue is unavailable');
    }
}
