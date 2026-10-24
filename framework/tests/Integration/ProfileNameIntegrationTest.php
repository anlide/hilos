<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\ProfileNameCommands;
use Hilos\Auth\Library\DTO\ProfileNameSetActionDTO;
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
        $this->previousAppClass = Hilos::appClass();
        $this->previousNotify = Hilos::$notify;
        Hilos::$notify = new HilosNotifier();
        ProfileNameTestSettings::$timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;
        Hilos::$setting = new ProfileNameTestSettings(ProfileNameTestSettingsCatalog::class);
        ProfileNameModeratedHilos::initBrowser(new ProfileNameTestBrowser());
        Hilos::$sr = new ProfileNameTestRouter();
        $this->nameLibrary = new ProfileNameTestLibrary();
        OwnershipDeclaration::claimAll($this->nameLibrary);
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
        RtStaleness::clear(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY]);
        TruthSourceRegistry::unregisterAgent($this->nameLibrary->getId());
        RtTruthSourceRegistry::unregisterAgent($this->nameLibrary->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->nameLibrary->getId()));
        Hilos::$notify = $this->previousNotify;
        $this->previousAppClass::initBrowser();
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

        $this->dispatchName('  Grace  ');
        $this->carry();
        $this->assertRenamedOnce('Grace');
        $this->dispatchName(' Grace ');
        $this->carry();
        self::assertSame(1, $this->personHops);
        $this->assertRenamedOnce('Grace');
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_MODERATION_CHECK));
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
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
        $this->assertError('Your new name is still being checked.');
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

    /** The owner's no-op covers two tabs whose identical requests were both checked. */
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
        ProfileNameTestSettings::$timeoutSec = 0;
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        $this->verdict(true, ModerationReason::OK);
        $this->assertUnavailable();
    }

    /** The library tick settles a stopped moderator with PROFILE_PHOTO disabled. */
    public function testSweepRunsWithoutProfilePhotoAndEndsExpiredStaleChecks(): void
    {
        self::assertFalse(Hilos::hasFeature(HilosFeature::PROFILE_PHOTO));
        ProfileNameTestSettings::$timeoutSec = 0;
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
        RtStaleness::mark(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY], microtime(true));
        $this->inNameLibrary(fn () => $this->nameLibrary->onTick());
        $this->assertUnavailable();
    }

    /** Stale replicas apply neither permission nor content refusal before their deadline. */
    public function testStaleConnectionWaitsAndThenFreshApprovalApplies(): void
    {
        $this->dispatchName('Grace');
        $request = $this->check()->requestId;
        $this->assertChecking([true]);
        RtStaleness::mark(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY], microtime(true));
        $this->verdict(true, ModerationReason::OK);
        $this->verdict(false, 'insult');
        $this->inNameLibrary(fn () => $this->nameLibrary->onTick());
        self::assertSame($request, $this->check()->requestId);
        $this->assertChecking([]);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $this->assertUnchanged();
        RtStaleness::clear(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY]);
        $this->verdict(true, ModerationReason::OK);
        $this->assertRenamedOnce('Grace');
    }

    /** Closed and changed connections are forgotten both by verdict delivery and by the sweep. */
    public function testClosedAndChangedConnectionsHaveNoOutcome(): void
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
        self::assertNull($this->findCheck(self::OTHER_ACCEPT_KEY));
        $this->assertChecking([]);
        self::assertSame([], $this->take(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->take(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $this->assertUnchanged();
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
        $this->dispatchName('Grace');
        $this->assertChecking([true]);
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
        }
        $this->carry();
        $this->assertError($merged
            ? 'Failed to update user: ' . AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE
            : 'User #' . self::USER_ID . ' not found');
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

    /** Carries the real owner ask and completion, retaining all other frames for assertions. */
    private function carry(): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($name === HilosSignalConstants::HILOS_USER_RENAME) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameSignalData::class, $signal->data->data);
                self::assertSame($this->expectedAuthor, $signal->data->data->renamedByUserId);
                self::assertSame($this->expectedAction, $signal->data->data->action);
                self::assertNull($signal->data->data->requestId);
                self::assertSame($this->expectedAuthor === self::USER_ID ? null : HilosSignalConstants::HILOS_USER_ADMIN_RENAME_DONE,
                    $signal->data->data->answerSignal);
                $this->personHops++;
                $this->deliverToPerson($signal);
            } elseif ($name === HilosSignalConstants::HILOS_USER_RENAME_DONE) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                self::assertInstanceOf(UserRenameDoneSignalData::class, $signal->data->data);
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

    /** An unavailable check affects only the requesting tab. */
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
        return $this->findCheck() ?? self::fail('No name check');
    }

    /**
     * @param string $acceptKey Connection
     * @return ?HilosModerationCheck Current name attempt, if any
     */
    private function findCheck(string $acceptKey = self::ACCEPT_KEY): ?HilosModerationCheck
    {
        return Hilos::$rt->hilosModerationChecks->find($acceptKey, ModerationKindKey::PROFILE_NAME);
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
