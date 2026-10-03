<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\Impersonation\ImpersonationMessages;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionAccountFrozenException;
use Hilos\Core\Page\Exception\PageAccountFrozenException;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAccessVerdict;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalHoldCommandConstants;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Pages\AbstractHilosProfileAgreementsHistoryPage;
use Hilos\Pages\AbstractHilosProfileAgreementsPage;
use Hilos\Pages\AbstractHilosProfileDataPage;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Pages\AbstractHilosTermsPage;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingChangeSubscriber;
use Hilos\Users\AccountStandingKind;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\TimeHelper;
use Throwable;

/**
 * An account's standing against the real tables, and the freeze it closes the product with (HIL-945).
 *
 * The catalog carries two substantial revisions of the terms, the second in force since March: a
 * person who accepted only the first is past the deadline, and under the freeze setting frozen.
 * The standing is composed from the person's row, their deletion request and their acceptance
 * records; the guard asks it on every page and every action a signed-in person asks for, and lets
 * through only the exits - the pages open while frozen and the actions their owners list.
 */
final class AccountStandingIntegrationTest extends ProfileIntegrationTestCase
{
    /** A signed-in tab of the person who is not frozen. */
    public const string STANDING_ACCEPT_KEY = 'accept-standing-other';

    /** The person behind that tab. */
    public const int STANDING_USER_ID = self::OTHER_USER_ID;

    /** Truth-source id the case writes the person table under. */
    private const string TEST_OWNER = 'account-standing-test';

    /** The deadline the second revision of the terms set. */
    private const string DEADLINE = '2026-03-01';

    private string $previousAppClass;

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        StandingIntegrationHilos::initBrowser(new StandingIntegrationBrowser());
        Hilos::$sr = new StandingIntegrationSignalRouter();
        $rt = new StandingIntegrationRtContext();
        $rt->mountFeatureRuntime([]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        StandingIntegrationSettings::$refusal = LegalSettings::REFUSAL_FREEZE;
        StandingIntegrationSettings::$impersonateFrozen = true;
        Hilos::$setting = new StandingIntegrationSettings(StandingIntegrationSettingsCatalog::class);
        TruthSourceRegistry::register(HilosDbContext::users, TruthSourceKeys::all(), self::TEST_OWNER);
        SourceChangeBus::subscribe(new AccountStandingChangeSubscriber());
        AccountStandingResolver::forgetAll();
    }

    protected function tearDown(): void
    {
        AccountStandingResolver::forgetAll();
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        TruthSourceRegistry::unregisterAgent(self::TEST_OWNER);
        Hilos::$sr = $this->previousRouter;
        $this->previousAppClass::initBrowser();
        parent::tearDown();
    }

    public function testAPersonHoldingOnlyTheEarlierRevisionIsFrozen(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        self::accept(self::USER_ID, 'privacy', 'privacy');

        $standing = AccountStandingResolver::of(self::USER_ID);

        self::assertSame(AccountStandingKind::FROZEN, $standing->shown);
        self::assertTrue($standing->frozen);
        self::assertFalse($standing->blocked);
        self::assertNull($standing->deletionEffectiveAt);
        self::assertSame(
            [[LegalDocument::TERMS, self::DEADLINE]],
            array_map(static fn ($lapsed): array => [$lapsed->document, $lapsed->deadline], $standing->lapsed),
        );
    }

    public function testAPersonWithoutAnyAcceptanceIsNotFrozen(): void
    {
        self::assertSame(AccountStandingKind::NONE, AccountStandingResolver::of(self::USER_ID)->shown);
        self::assertFalse(AccountStandingResolver::isFrozen(self::USER_ID));
    }

    public function testRemindNamesTheLapseWithoutFreezing(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        StandingIntegrationSettings::$refusal = LegalSettings::REFUSAL_REMIND;

        $standing = AccountStandingResolver::of(self::USER_ID);

        self::assertFalse($standing->frozen);
        self::assertSame(AccountStandingKind::NONE, $standing->shown);
        self::assertCount(1, $standing->lapsed);
    }

    public function testChangingTheSettingStartsANewEpochWithoutAnyAnnouncement(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));

        StandingIntegrationSettings::$refusal = LegalSettings::REFUSAL_REMIND;
        self::assertFalse(AccountStandingResolver::isFrozen(self::USER_ID));

        StandingIntegrationSettings::$refusal = LegalSettings::REFUSAL_FREEZE;
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));
    }

    public function testARevisionPublishedFreezesOnTheFirstReadWithoutASweep(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        StandingNotYetInForceHilos::initBrowser(new StandingIntegrationBrowser());
        self::assertFalse(AccountStandingResolver::isFrozen(self::USER_ID));

        // Publishing is a start with the new catalog: nothing is remembered, nothing is swept.
        AccountStandingResolver::forgetAll();
        StandingIntegrationHilos::initBrowser(new StandingIntegrationBrowser());
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));
    }

    public function testAFaultyCatalogFreezesNobody(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        StandingBrokenCatalogHilos::initBrowser(new StandingIntegrationBrowser());

        $standing = AccountStandingResolver::of(self::USER_ID);

        self::assertSame([], $standing->lapsed);
        self::assertFalse($standing->frozen);
        self::assertSame([], AccountStandingResolver::lapsedUserIds(LegalDocument::TERMS));
    }

    public function testBlockingAndUnblockingSomeoneWhoHasNotAcceptedLeavesThemFrozen(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        self::assertSame(AccountStandingKind::FROZEN, AccountStandingResolver::of(self::USER_ID)->shown);

        Hilos::$db->users[self::USER_ID]->actions->setBlock(true);
        $blocked = AccountStandingResolver::of(self::USER_ID);
        self::assertSame(AccountStandingKind::BLOCKED, $blocked->shown);
        self::assertTrue($blocked->frozen);

        Hilos::$db->users[self::USER_ID]->actions->setBlock(false);
        self::assertSame(AccountStandingKind::FROZEN, AccountStandingResolver::of(self::USER_ID)->shown);
    }

    public function testAWriteOfAnyFactDropsTheRememberedVerdict(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));

        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'second']);
        self::assertSame(AccountStandingKind::NONE, AccountStandingResolver::of(self::USER_ID)->shown);

        $request = Hilos::$db->accountDeletions->actions->request(
            self::USER_ID,
            date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY),
        );
        $scheduled = AccountStandingResolver::of(self::USER_ID);
        self::assertSame(AccountStandingKind::DELETION_SCHEDULED, $scheduled->shown);
        self::assertSame(TimeHelper::sqlToMs($request->effectiveAt), $scheduled->deletionEffectiveAt);

        // The cancel updates columns that do not name the person, so every verdict is dropped.
        $request->actions->cancel();
        self::assertSame(AccountStandingKind::NONE, AccountStandingResolver::of(self::USER_ID)->shown);
    }

    public function testOnlyAChangeCarryingTheBlockDropsAPersonsVerdict(): void
    {
        $remembered = AccountStandingResolver::of(self::USER_ID);

        SourceChangeBus::publish(SourceChange::dbUpdated(
            HilosDbContext::users,
            (string)self::USER_ID,
            ['last_activity' => '2026-09-29 10:00:00'],
        ));
        self::assertSame($remembered, AccountStandingResolver::of(self::USER_ID));

        SourceChangeBus::publish(SourceChange::dbUpdated(HilosDbContext::users, (string)self::USER_ID, [EntityUser::block => true]));
        self::assertNotSame($remembered, AccountStandingResolver::of(self::USER_ID));
    }

    public function testTheLapsedListAgreesWithTheCountOfTheLegalRoot(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        self::accept(self::OTHER_USER_ID, 'terms', 'first');
        self::accept(self::OTHER_USER_ID, 'terms', 'second');
        self::accept(self::ADMIN_USER_ID, 'privacy', 'privacy');

        $lapsed = AccountStandingResolver::lapsedUserIds(LegalDocument::TERMS);

        self::assertSame([self::USER_ID], $lapsed);
        self::assertSame(count($lapsed), LegalTally::all(LegalStandingResolver::today())['terms']->lapsed);
        self::assertSame([], AccountStandingResolver::lapsedUserIds(LegalDocument::PRIVACY));
    }

    public function testTheGateRefusesAFrozenPersonEveryPageButTheExits(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');

        foreach ([AbstractHilosProfilePage::class, AbstractHilosUserPage::class] as $closed) {
            try {
                PageAccessGate::verdict($closed, self::ACCEPT_KEY);
                self::fail("{$closed} must refuse a frozen person");
            } catch (PageAccountFrozenException $e) {
                self::assertSame(403, $e->httpCode);
                self::assertSame('account_frozen', $e->errorCode);
            }
        }
        foreach ([
            AbstractHilosProfileDataPage::class,
            AbstractHilosProfileAgreementsPage::class,
            AbstractHilosProfileAgreementsHistoryPage::class,
            AbstractHilosTermsPage::class,
        ] as $open) {
            self::assertSame(PageAccessVerdict::ALLOW, PageAccessGate::verdict($open, self::ACCEPT_KEY), $open);
        }
        self::assertSame(PageAccessVerdict::ALLOW, PageAccessGate::verdict(AbstractHilosProfilePage::class, self::STANDING_ACCEPT_KEY));
    }

    public function testAFrozenAdministratorHearsTheFreezeRatherThanAMissingRight(): void
    {
        self::accept(self::ADMIN_USER_ID, 'terms', 'first');

        $this->expectException(PageAccountFrozenException::class);
        PageAccessGate::verdict(AbstractHilosUserPage::class, self::ADMIN_ACCEPT_KEY);
    }

    /**
     * A frozen person may be taken over by default, and not once the row of the frozen is switched off (HIL-1170).
     *
     * The freeze is not a punishment and is a row of its own, apart from the block: a person who is
     * not frozen is not touched by it.
     */
    public function testAFrozenPersonIsTakenOverOnlyWhileThatRowIsOn(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        $sessions = new StandingImpersonationLibrary();

        $sessions->mayImpersonate(self::ADMIN_USER_ID, self::USER_ID);

        StandingIntegrationSettings::$impersonateFrozen = false;
        $sessions->mayImpersonate(self::ADMIN_USER_ID, self::OTHER_USER_ID);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(ImpersonationMessages::FROZEN_OFF);

        $sessions->mayImpersonate(self::ADMIN_USER_ID, self::USER_ID);
    }

    public function testAFrozenPersonsSubscriptionIsRefusedWithTheFreezesCode(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        $factory = new StandingTestPageFactory(new StandingTestAgent());

        $this->pageRouter($factory)->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, StandingTestProductPage::PAGE, []),
            SignalSource::WEBSOCKET,
            StandingTestProductPage::PAGE,
        );
        $this->pageRouter($factory)->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, StandingTestExitPage::PAGE, []),
            SignalSource::WEBSOCKET,
            StandingTestExitPage::PAGE,
        );

        $product = $factory->getPage(StandingTestProductPage::PAGE);
        self::assertInstanceOf(StandingTestProductPage::class, $product);
        self::assertFalse($product->subscribed);
        $exit = $factory->getPage(StandingTestExitPage::PAGE);
        self::assertInstanceOf(StandingTestExitPage::class, $exit);
        self::assertTrue($exit->subscribed);
        $error = $this->queued(SignalConstants::SUBSCRIPTION_PAGE_ERROR);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        self::assertSame(403, $error->httpCode);
        self::assertSame('account_frozen', $error->errorCode);
    }

    public function testAPublicPageClosesItsWritesToAFrozenPersonAndLeavesItsExits(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        $factory = new StandingTestPageFactory(new StandingTestAgent());
        $router = $this->pageRouter($factory);

        $router->dispatchAction(new WebSocketActionSignalDTO(self::ACCEPT_KEY, StandingTestPublicPage::WRITE), SignalSource::WEBSOCKET);
        $page = $factory->getPage(StandingTestPublicPage::PAGE);
        self::assertInstanceOf(StandingTestPublicPage::class, $page);
        self::assertSame([], $page->handled);
        self::assertInstanceOf(ActionAccountFrozenException::class, $page->actionException);

        $page->actionException = null;
        $router->dispatchAction(new WebSocketActionSignalDTO(self::ACCEPT_KEY, StandingTestPublicPage::EXIT), SignalSource::WEBSOCKET);
        $router->dispatchAction(new WebSocketActionSignalDTO(self::ACCEPT_KEY, StandingTestPublicPage::READ), SignalSource::WEBSOCKET);
        $router->dispatchAction(new WebSocketActionSignalDTO(self::ACCEPT_KEY, StandingTestExitPage::WRITE), SignalSource::WEBSOCKET);
        self::assertSame([StandingTestPublicPage::EXIT, StandingTestPublicPage::READ], $page->handled);
        self::assertNull($page->actionException);
        $exit = $factory->getPage(StandingTestExitPage::PAGE);
        self::assertInstanceOf(StandingTestExitPage::class, $exit);
        self::assertSame([StandingTestExitPage::WRITE], $exit->handled);
    }

    public function testTheLibraryClosesItsCommandsToAFrozenPersonButTheCancelOfTheirDeletion(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        Hilos::$db->accountDeletions->actions->request(self::USER_ID, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));
        $router = $this->pageRouter(new StandingTestPageFactory($this->library));

        $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN, [], 'req-open'),
            SignalSource::WEBSOCKET,
        );
        $refusal = $this->queued(SignalConstants::ACTION_ERROR);
        self::assertInstanceOf(PageActionErrorSignalData::class, $refusal);
        self::assertSame('req-open', $refusal->requestId);
        self::assertSame('account_frozen', $refusal->errorCode);

        $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL, [], 'req-cancel'),
            SignalSource::WEBSOCKET,
        );
        $answer = $this->queued(SignalConstants::ACTION_SUCCESS);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $answer);
        self::assertSame('req-cancel', $answer->requestId);
        self::assertNull(Hilos::$db->accountDeletions->liveOf(self::USER_ID));
    }

    public function testAFrozenPersonReadsTheNewTermsAndAcceptsThemPastTheGate(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        self::accept(self::USER_ID, 'privacy', 'privacy');
        $router = $this->pageRouter(new StandingTestPageFactory($this->library));

        $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_RECONSENT, [], 'req-read'),
            SignalSource::WEBSOCKET,
        );
        $read = $this->queued(SignalConstants::ACTION_SUCCESS);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $read);
        self::assertSame('req-read', $read->requestId);

        $router->dispatchAction(
            new WebSocketActionSignalDTO(
                self::ACCEPT_KEY,
                HilosSignalConstants::HILOS_LEGAL_ACCEPT,
                ['acceptedRevisions' => ['terms' => 'second']],
                'req-accept',
            ),
            SignalSource::WEBSOCKET,
        );
        $accepted = $this->queued(SignalConstants::ACTION_SUCCESS);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $accepted);
        self::assertSame('req-accept', $accepted->requestId);
        self::assertFalse(AccountStandingResolver::isFrozen(self::USER_ID));
    }

    public function testATickTellsEverySessionOfAPersonWhoseStandingMovedAndReDecidesTheirPages(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        $sessions = new StandingSessionsLibrary();

        // Seen for the first time: the tabs were given the standing with the frame that brought them.
        $sessions->onTick();
        self::assertSame([], $this->drained()[HilosSignalConstants::HILOS_SESSION_STATE] ?? []);

        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'second']);
        $this->drained();
        $sessions->onTick();

        $drained = $this->drained();
        $states = $drained[HilosSignalConstants::HILOS_SESSION_STATE] ?? [];
        $acceptKeys = array_map(static fn (SessionStateSignalData $state): array => $state->acceptKeys, $states);
        sort($acceptKeys);
        self::assertSame([[self::ACCEPT_KEY], [self::OTHER_ACCEPT_KEY]], $acceptKeys);
        foreach ($states as $state) {
            self::assertSame(self::USER_ID, $state->userId);
            self::assertSame('none', $state->accountStanding[AccountStanding::shown] ?? null);
            self::assertFalse($state->accountStanding[AccountStanding::frozen] ?? null);
        }
        self::assertSame(
            [self::USER_ID],
            array_map(
                static fn (PageAccessReassessUserSignalData $reassess): int => $reassess->userId,
                $drained[SignalConstants::PAGE_ACCESS_REASSESS_USER] ?? [],
            ),
        );

        $sessions->onTick();
        self::assertSame([], $this->drained()[HilosSignalConstants::HILOS_SESSION_STATE] ?? []);
    }

    public function testAChangeThatLeavesTheFreezeAloneTellsTheTabsWithoutReDecidingThePages(): void
    {
        $sessions = new StandingSessionsLibrary();
        $sessions->onTick();

        Hilos::$db->accountDeletions->actions->request(self::USER_ID, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));
        $sessions->onTick();

        $drained = $this->drained();
        $states = $drained[HilosSignalConstants::HILOS_SESSION_STATE] ?? [];
        self::assertCount(2, $states);
        foreach ($states as $state) {
            self::assertSame('deletion_scheduled', $state->accountStanding[AccountStanding::shown] ?? null);
            self::assertIsInt($state->accountStanding[AccountStanding::deletionEffectiveAt] ?? null);
        }
        self::assertArrayNotHasKey(SignalConstants::PAGE_ACCESS_REASSESS_USER, $drained);
    }

    public function testHoldCommandCausesLapseAndFreezesAccountAndNotifiesSessions(): void
    {
        self::accept(self::USER_ID, 'terms', 'second');
        self::accept(self::USER_ID, 'privacy', 'privacy');
        $sessions = new StandingSessionsLibrary();
        $sessions->onTick();
        $this->drained();

        $this->library->onSignalCommand(
            new CommandRequestDTO('corr-1', CliCommands::LEGAL_TEST_HOLD, [
                LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
            ]),
            '',
            '',
        );

        $drained = $this->drained();
        $reply = $drained['corr-1'][0] ?? null;
        self::assertInstanceOf(CommandReplyDTO::class, $reply);
        self::assertTrue($reply->isOk());
        self::assertSame([
            LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
            LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
            LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
            LegalHoldCommandConstants::FIELD_STANDING => 'lapsed',
            LegalHoldCommandConstants::FIELD_DEADLINE => '2026-03-01',
            LegalHoldCommandConstants::FIELD_FROZEN => true,
        ], $reply->payload);

        $byDoc = [];
        foreach (Hilos::$db->legalAcceptances->ofUser(self::USER_ID) as $acceptance) {
            $byDoc[$acceptance->document][] = $acceptance->revisionId;
        }
        self::assertSame(['first'], $byDoc['terms'] ?? []);
        self::assertSame(['privacy'], $byDoc['privacy'] ?? []);

        self::assertSame(AccountStandingKind::FROZEN, AccountStandingResolver::of(self::USER_ID)->shown);
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));
        self::assertNotEmpty($drained[HilosSignalConstants::HILOS_LEGAL_AGREEMENTS_STATE] ?? []);

        $sessions->onTick();
        $drainedSessions = $this->drained();
        $states = $drainedSessions[HilosSignalConstants::HILOS_SESSION_STATE] ?? [];
        self::assertCount(2, $states);
        foreach ($states as $state) {
            self::assertSame(self::USER_ID, $state->userId);
            self::assertTrue($state->accountStanding[AccountStanding::frozen] ?? false);
        }
        self::assertSame(
            [self::USER_ID],
            array_map(
                static fn (PageAccessReassessUserSignalData $reassess): int => $reassess->userId,
                $drainedSessions[SignalConstants::PAGE_ACCESS_REASSESS_USER] ?? [],
            ),
        );
    }

    public function testHoldCommandUnderNotYetInForceCatalogOpensWindowWithoutFreezing(): void
    {
        try {
            StandingNotYetInForceHilos::initBrowser(new StandingIntegrationBrowser());
            self::accept(self::USER_ID, 'terms', 'second');
            self::accept(self::USER_ID, 'privacy', 'privacy');

            $this->library->onSignalCommand(
                new CommandRequestDTO('corr-1', CliCommands::LEGAL_TEST_HOLD, [
                    LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                    LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                    LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
                ]),
                '',
                '',
            );

            $drained = $this->drained();
            $reply = $drained['corr-1'][0] ?? null;
            self::assertInstanceOf(CommandReplyDTO::class, $reply);
            self::assertTrue($reply->isOk());
            self::assertSame([
                LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
                LegalHoldCommandConstants::FIELD_STANDING => 'window',
                LegalHoldCommandConstants::FIELD_DEADLINE => '2999-01-01',
                LegalHoldCommandConstants::FIELD_FROZEN => false,
            ], $reply->payload);
        } finally {
            StandingIntegrationHilos::initBrowser(new StandingIntegrationBrowser());
        }
    }

    public function testHoldCommandToCurrentRevisionCoversAndPreservesEarlierAcceptance(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');

        $this->library->onSignalCommand(
            new CommandRequestDTO('corr-1', CliCommands::LEGAL_TEST_HOLD, [
                LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'second',
            ]),
            '',
            '',
        );

        $drained = $this->drained();
        $reply = $drained['corr-1'][0] ?? null;
        self::assertInstanceOf(CommandReplyDTO::class, $reply);
        self::assertTrue($reply->isOk());
        self::assertSame([
            LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
            LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
            LegalHoldCommandConstants::FIELD_REVISION_ID => 'second',
            LegalHoldCommandConstants::FIELD_STANDING => 'covered',
            LegalHoldCommandConstants::FIELD_DEADLINE => null,
            LegalHoldCommandConstants::FIELD_FROZEN => false,
        ], $reply->payload);

        $revisions = array_map(
            static fn ($acceptance): string => $acceptance->revisionId,
            array_values(array_filter(
                Hilos::$db->legalAcceptances->ofUser(self::USER_ID),
                static fn ($acceptance): bool => $acceptance->document === 'terms',
            )),
        );
        self::assertContains('first', $revisions);
        self::assertContains('second', $revisions);
    }

    public function testHoldCommandUnderRemindSettingLapsesWithoutFreezing(): void
    {
        try {
            StandingIntegrationSettings::$refusal = LegalSettings::REFUSAL_REMIND;
            self::accept(self::USER_ID, 'terms', 'second');

            $this->library->onSignalCommand(
                new CommandRequestDTO('corr-1', CliCommands::LEGAL_TEST_HOLD, [
                    LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                    LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                    LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
                ]),
                '',
                '',
            );

            $drained = $this->drained();
            $reply = $drained['corr-1'][0] ?? null;
            self::assertInstanceOf(CommandReplyDTO::class, $reply);
            self::assertTrue($reply->isOk());
            self::assertSame([
                LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
                LegalHoldCommandConstants::FIELD_STANDING => 'lapsed',
                LegalHoldCommandConstants::FIELD_DEADLINE => '2026-03-01',
                LegalHoldCommandConstants::FIELD_FROZEN => false,
            ], $reply->payload);
        } finally {
            StandingIntegrationSettings::$refusal = LegalSettings::REFUSAL_FREEZE;
        }
    }

    public function testHoldCommandRefusalsLeaveAcceptanceCountUnchanged(): void
    {
        self::accept(self::USER_ID, 'terms', 'first');
        $countBefore = count(Hilos::$db->legalAcceptances);

        $this->library->onSignalCommand(
            new CommandRequestDTO('corr-1', CliCommands::LEGAL_TEST_HOLD, [
                LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'cookies',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
            ]),
            '',
            '',
        );
        $reply1 = $this->drained()['corr-1'][0] ?? null;
        self::assertInstanceOf(CommandReplyDTO::class, $reply1);
        self::assertFalse($reply1->isOk());
        self::assertSame('Legal document cookies is not declared in this installation', $reply1->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        self::assertSame($countBefore, count(Hilos::$db->legalAcceptances));

        $this->library->onSignalCommand(
            new CommandRequestDTO('corr-2', CliCommands::LEGAL_TEST_HOLD, [
                LegalHoldCommandConstants::FIELD_USER_ID => 999999,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'first',
            ]),
            '',
            '',
        );
        $reply2 = $this->drained()['corr-2'][0] ?? null;
        self::assertInstanceOf(CommandReplyDTO::class, $reply2);
        self::assertFalse($reply2->isOk());
        self::assertSame('No such user: 999999', $reply2->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        self::assertSame($countBefore, count(Hilos::$db->legalAcceptances));

        $this->library->onSignalCommand(
            new CommandRequestDTO('corr-3', CliCommands::LEGAL_TEST_HOLD, [
                LegalHoldCommandConstants::FIELD_USER_ID => self::USER_ID,
                LegalHoldCommandConstants::FIELD_DOCUMENT => 'terms',
                LegalHoldCommandConstants::FIELD_REVISION_ID => 'third',
            ]),
            '',
            '',
        );
        $reply3 = $this->drained()['corr-3'][0] ?? null;
        self::assertInstanceOf(CommandReplyDTO::class, $reply3);
        self::assertFalse($reply3->isOk());
        self::assertSame('Legal document terms declares no revision third', $reply3->payload[CommandConstants::FIELD_MESSAGE] ?? null);
        self::assertSame($countBefore, count(Hilos::$db->legalAcceptances));
    }

    public function testUsersLibraryDeclaresLegalTestHoldCommand(): void
    {
        self::assertContains(CliCommands::LEGAL_TEST_HOLD, ProfileIntegrationLibrary::AGENT_COMMANDS);
    }

    /**
     * Inserts an acceptance the way a record made before the case would lie in the table.
     *
     * @param int $userId Person who accepted
     * @param string $document Stored document key
     * @param string $revisionId Revision accepted
     * @throws HilosException When the insert fails
     */
    private static function accept(int $userId, string $document, string $revisionId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [$userId, $document, $revisionId, '2026-01-02 00:00:00'],
        );
    }

    /**
     * @param StandingTestPageFactory $factory Pages and agent the router serves
     * @return PageSignalRouter Router with the fixture pages' actions registered
     */
    private function pageRouter(StandingTestPageFactory $factory): PageSignalRouter
    {
        return new PageSignalRouter($factory, new ActionRouteConfig([
            StandingTestPublicPage::WRITE => StandingTestPublicPage::PAGE,
            StandingTestPublicPage::EXIT => StandingTestPublicPage::PAGE,
            StandingTestPublicPage::READ => StandingTestPublicPage::PAGE,
            StandingTestExitPage::WRITE => StandingTestExitPage::PAGE,
        ]));
    }

    /**
     * Drains the queue and files every payload by its signal name, whether it went to a socket or to an agent.
     *
     * @return array<string, list<SignalDataInterface>> Payloads in queue order, by signal name
     */
    private function drained(): array
    {
        $found = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $found[$signal->signalName->getName()][] = $signal->data instanceof AgentSignalData || $signal->data instanceof WebSocketSignalData
                ? $signal->data->data
                : $signal->data;
        }

        return $found;
    }

    /**
     * Drains the queue and returns the first payload sent under one signal name.
     *
     * @param string $signalName Signal name to match
     * @return ?object Wrapped payload, or null when none was queued
     */
    private function queued(string $signalName): ?object
    {
        $found = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($found === null && $signal->signalName->getName() === $signalName && $signal->data instanceof WebSocketSignalData) {
                $found = $signal->data->data;
            }
        }

        return $found;
    }
}

/** Terms whose second substantial revision has been in force since March; privacy in one revision. */
final class StandingIntegrationCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}

/** The same terms, with the second revision published and not yet in force. */
final class StandingNotYetInForceCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2999-01-01', []),
            ],
        ];
    }
}

/** A catalog whose declaration is refused. */
final class StandingBrokenCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array Never returns
     * @throws UnknownRevisionException Always refuses the fixture catalog
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken standing catalog');
    }
}

/** Binds the in-force catalog and the users library whose actions the router dispatches. */
abstract class StandingIntegrationHilos extends Hilos
{
    public const array AGENTS = [
        ProfileIntegrationLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => ProfileIntegrationLibrary::class],
    ];

    protected const ?string LEGAL_CATALOG = StandingIntegrationCatalog::class;
}

/** Binds the catalog whose second revision is not in force yet. */
abstract class StandingNotYetInForceHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = StandingNotYetInForceCatalog::class;
}

/** Binds the refused catalog. */
abstract class StandingBrokenCatalogHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = StandingBrokenCatalog::class;
}

/** Signal router reading agent actions from the fixture facade. */
final class StandingIntegrationSignalRouter extends SignalRouter
{
    /**
     * @return string Fixture facade holding the users library
     */
    protected function hilosClass(): string
    {
        return StandingIntegrationHilos::class;
    }
}

/** The profile's settings with the legal ones, the refusal treatment scripted by the case. */
final class StandingIntegrationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        return array_replace(
            StepUpSettingsCatalog::getCatalog(),
            SecondFactorSettingsCatalog::getCatalog(),
            AccountDeletionSettingsCatalog::getCatalog(),
            LegalSettingsCatalog::getCatalog(),
            ImpersonationSettingsCatalog::getCatalog(),
        );
    }
}

/** Settings whose refusal treatment a case switches at the persistence seam. */
final class StandingIntegrationSettings extends SettingsAccessor
{
    /** Treatment of a refusal after the deadline the case has set. */
    public static string $refusal = LegalSettings::REFUSAL_FREEZE;

    /** Whether a frozen person may be taken over, as the case has set it (HIL-1170). */
    public static bool $impersonateFrozen = true;

    /**
     * @param string $key Setting key
     * @return mixed The scripted refusal treatment or takeover of the frozen, or the stored value of any other key
     * @throws HilosException When another key cannot be read
     */
    public function effectiveValueFor(string $key): mixed
    {
        return match ($key) {
            LegalSettings::REFUSAL_KEY => self::$refusal,
            ImpersonationSettings::FROZEN_KEY => self::$impersonateFrozen,
            default => parent::effectiveValueFor($key),
        };
    }
}

/** Resolves the fixture tabs to their people. */
final class StandingIntegrationBrowser extends BrowserContext
{
    /**
     * @param string $acceptKey Acting connection accept key
     * @return ConnectionIdentity Settled identity of the tab
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(match ($acceptKey) {
            ProfileIntegrationTestCase::ACCEPT_KEY => ProfileIntegrationTestCase::USER_ID,
            ProfileIntegrationTestCase::ADMIN_ACCEPT_KEY => ProfileIntegrationTestCase::ADMIN_USER_ID,
            AccountStandingIntegrationTest::STANDING_ACCEPT_KEY => AccountStandingIntegrationTest::STANDING_USER_ID,
            default => null,
        });
    }
}

/** Payload of every fixture page action; they carry no data. */
final class StandingTestActionDTO extends ActionPayloadDTO
{
    /**
     * @return string Action name this payload stands for
     */
    public function getAction(): string
    {
        return StandingTestPublicPage::WRITE;
    }

    /**
     * @return array<string, mixed> Empty wire payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Wire payload (unused by the fixture)
     * @return static Payload instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

/** Records what reached the handlers of a fixture page. */
abstract class StandingTestPage extends AbstractPage
{
    public bool $subscribed = false;

    /** @var list<string> Actions whose handler ran, in order */
    public array $handled = [];

    public ?Throwable $actionException = null;

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param PageRouteParams $params Route params (unused)
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $this->subscribed = true;
    }

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action whose handler ran
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @return ?ActionReplyDTO Always null
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        $this->handled[] = $action;

        return null;
    }

    /**
     * @param string $acceptKey WebSocket accept key (unused)
     * @param string $action Action name (unused)
     * @param ActionPayloadDTO $dto Action payload (unused)
     * @param Throwable $e Refusal the dispatcher raised
     */
    public function onActionException(string $acceptKey, string $action, ActionPayloadDTO $dto, Throwable $e): void
    {
        $this->actionException = $e;
    }
}

/** A page of the product for signed-in people. */
final class StandingTestProductPage extends StandingTestPage
{
    public const string PAGE = 'standing_product';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
}

/** A page for signed-in people that is one of the exits, with a write of its own. */
final class StandingTestExitPage extends StandingTestPage
{
    public const string PAGE = 'standing_exit';
    public const string WRITE = 'standing_exit_write';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const bool OPEN_WHILE_FROZEN = true;

    public const array ACTIONS = [self::WRITE => StandingTestActionDTO::class];

    public const array AUTH_ACTIONS = [self::WRITE];
}

/** A page everyone reads, whose writes need a signed-in person - the shape of the chat's main page. */
final class StandingTestPublicPage extends StandingTestPage
{
    public const string PAGE = 'standing_public';
    public const string WRITE = 'standing_public_write';
    public const string EXIT = 'standing_public_exit';
    public const string READ = 'standing_public_read';

    public const array ACTIONS = [
        self::WRITE => StandingTestActionDTO::class,
        self::EXIT => StandingTestActionDTO::class,
        self::READ => StandingTestActionDTO::class,
    ];

    public const array AUTH_ACTIONS = [self::WRITE, self::EXIT];

    public const array FROZEN_EXIT_ACTIONS = [self::EXIT];
}

/**
 * Fixture pages over whichever agent the case hands in.
 *
 * @extends AbstractPageFactory<PageAgentInterface>
 */
final class StandingTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        return match ($pageName) {
            StandingTestProductPage::PAGE => new StandingTestProductPage($this->agent),
            StandingTestExitPage::PAGE => new StandingTestExitPage($this->agent),
            StandingTestPublicPage::PAGE => new StandingTestPublicPage($this->agent),
            default => throw new PageNotFoundException($pageName),
        };
    }

    /**
     * @param string $pageName Page name
     * @return bool Whether the page is one of the fixtures
     */
    public function hasPage(string $pageName): bool
    {
        return in_array($pageName, [StandingTestProductPage::PAGE, StandingTestExitPage::PAGE, StandingTestPublicPage::PAGE], true);
    }
}

/** An agent that is only a signal source for the fixture pages. */
final class StandingTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'standing-test-agent';
    }

    /**
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'standing-test');
    }
}

/** The sessions library of the fixture, with nothing of a project in it. */
final class StandingSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/** The framework's sessions library with its takeover check opened to the case (HIL-1170). */
final class StandingImpersonationLibrary extends AbstractSessionsLibraryAgent
{
    /**
     * Opens the protected takeover check to the test.
     *
     * @param int $adminUserId User the acting session currently carries
     * @param int $targetUserId User that session asks to act as
     * @throws HilosException Whatever the framework's check raises
     */
    public function mayImpersonate(int $adminUserId, int $targetUserId): void
    {
        $this->assertImpersonationAllowed($adminUserId, $targetUserId);
    }
}

/** The profile's tabs over a node with the session features mounted, so the holder's tick runs whole. */
final class StandingIntegrationRtContext extends RtContext
{
    /**
     * Mounts the two tabs of the person, the administrator's and the signed-out one.
     */
    public function configure(): void
    {
        $connections = ProfileIntegrationConnections::init();
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::ACCEPT_KEY,
            ProfileIntegrationTestCase::USER_ID,
            ProfileIntegrationTestCase::SESSION_TOKEN,
        ));
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::OTHER_ACCEPT_KEY,
            ProfileIntegrationTestCase::USER_ID,
            ProfileIntegrationTestCase::OTHER_SESSION_TOKEN,
        ));
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::ADMIN_ACCEPT_KEY,
            ProfileIntegrationTestCase::ADMIN_USER_ID,
        ));
        $connections->add(ProfileIntegrationConnection::create(
            ProfileIntegrationTestCase::ANONYMOUS_ACCEPT_KEY,
            null,
            ProfileIntegrationTestCase::ANONYMOUS_SESSION_TOKEN,
        ));
        $this->_stateCollections[ProfileIntegrationConnections::RT_COLLECTION] = $connections;
    }
}
