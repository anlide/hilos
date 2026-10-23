<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\View\Collection\AccountDeletions;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Collection\Countries;
use Hilos\Database\View\Collection\Languages;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\I18n\DTO\I18nOverviewData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Pages\AbstractHilosI18nPage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real worker re-send, page verdict, builder and wire; only the persisted row storage is in memory. */
final class I18nOverviewFanoutTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private string $previousHilosClass;
    private string|false $previousDefaultLanguage;
    private ObjectLanguages $languageObjects;
    private ObjectCountries $countryObjects;
    private I18nOverviewFanoutBrowser $browser;
    private I18nOverviewFanoutWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousHilosClass = Hilos::appClass();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        Hilos::$env = new EnvAccessor();
        $this->browser = new I18nOverviewFanoutBrowser();
        I18nOverviewFanoutHilos::initBrowser($this->browser);
        $this->worker = new I18nOverviewFanoutWorker(1);
        $this->browser->bindPageResender($this->worker);
        $this->languageObjects = ObjectLanguages::initEmpty();
        $this->countryObjects = ObjectCountries::initEmpty();
        $languages = Languages::init();
        $languages->setObjectCollection($this->languageObjects);
        $countries = Countries::init();
        $countries->setObjectCollection($this->countryObjects);
        $deletions = $this->createStub(AccountDeletions::class);
        $merges = $this->createStub(UserMerges::class);
        $users = $this->createStub(Users::class);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnCallback(static function (string $key) use (
            $languages, $countries, $deletions, $merges, $users,
        ): mixed {
            if (in_array($key, AbstractHilosI18nPage::READS_DB, true)) {
                self::assertSame(HilosAgentType::HILOS_I18N_LIBRARY, ExecutionContext::currentAgentId());
                self::assertSame('overview', ExecutionContext::currentAcceptKey());
            }
            return match ($key) {
                HilosDbContext::languages => $languages,
                HilosDbContext::countries => $countries,
                HilosDbContext::accountDeletions => $deletions,
                HilosDbContext::userMerges => $merges,
                HilosDbContext::users => $users,
                default => null,
            };
        });
        $this->language(11, 'en', true);
        $this->language(12, 'fr', false);
        $this->country(21, 'us', false);
        $this->country(22, 'qx', true);
        $this->subscribe();
    }

    protected function tearDown(): void
    {
        Database::transactionRollback();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('overview'));
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        $this->previousHilosClass::initBrowser($this->previousBrowser);
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, TableMutationType}> Every mutation of both declared sources */
    public static function overviewFacts(): iterable
    {
        foreach (AbstractHilosI18nPage::READS_DB as $source) {
            foreach (TableMutationType::cases() as $mutation) {
                yield $source . '-' . $mutation->value => [$source, $mutation];
            }
        }
    }

    /**
     * @param string $source Changed collection
     * @param TableMutationType $mutation Committed change kind
     */
    #[DataProvider('overviewFacts')]
    public function testEveryOwnSourceMutationSendsOneWholePage(string $source, TableMutationType $mutation): void
    {
        $this->languageObjects[11]->nativeName = 'Current English';
        if ($source === HilosDbContext::languages) {
            match ($mutation) {
                TableMutationType::Create => $this->language(13, 'qx', true),
                TableMutationType::Update => $this->languageObjects[12]->enabled = true,
                TableMutationType::Delete => $this->languageObjects->offsetUnset(12),
                TableMutationType::Clear => $this->languageObjects->clearInMemory(),
            };
        } else {
            match ($mutation) {
                TableMutationType::Create => $this->country(23, 'gb', true),
                TableMutationType::Update => $this->countryObjects[21]->enabled = true,
                TableMutationType::Delete => $this->countryObjects->offsetUnset(22),
                TableMutationType::Clear => $this->countryObjects->clearInMemory(),
            };
        }
        $this->browser->record(new SourceChange(SourceChange::KIND_DB, $source, '99', $mutation));
        $this->flush();
        if ($source === HilosDbContext::languages && $mutation === TableMutationType::Clear) {
            $frames = $this->frames();
            self::assertCount(1, $frames);
            self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]->signalName->getName());
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        } elseif ($source === HilosDbContext::languages) {
            match ($mutation) {
                TableMutationType::Create => $this->assertOverview(2, 3, 1, 2, 1, 1),
                TableMutationType::Update => $this->assertOverview(2, 2, 1, 2, 1, 1),
                TableMutationType::Delete => $this->assertOverview(1, 1, 1, 2, 1, 1),
            };
        } else {
            match ($mutation) {
                TableMutationType::Create => $this->assertOverview(1, 2, 2, 3, 2, 1),
                TableMutationType::Update => $this->assertOverview(1, 2, 2, 2, 1, 1),
                TableMutationType::Delete => $this->assertOverview(1, 2, 0, 1, 1, 0),
                TableMutationType::Clear => $this->assertOverview(1, 2, 0, 0, 0, 0),
            };
        }
        $this->flush();
        self::assertSame([], $this->frames());
    }

    public function testBothSourcesAndManyRowsAreFoldedIntoOneWholeAnswerPerFlush(): void
    {
        $this->languageObjects[11]->nativeName = 'Current English';
        $this->languageObjects[12]->enabled = true;
        $this->country(23, 'gb', true);
        $this->recordBothSources();
        $this->browser->record(SourceChange::dbCreated(HilosDbContext::countries, '23', []));
        $this->browser->recordDatabaseBack();

        $this->flush();
        $this->assertOverview(2, 2, 2, 3, 2, 1);
    }

    public function testForeignDatabaseSourcesAndRuntimeNamesakesDoNotRedeliver(): void
    {
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::locales, '1', []));
        $this->browser->record(SourceChange::rtUpdated(HilosDbContext::languages, '11', []));
        $this->flush();

        self::assertSame([], $this->frames());
    }

    public function testRolledBackAnnouncementsDoNotReachThePage(): void
    {
        Database::transactionStart();
        Database::afterCommit(fn () => $this->recordBothSources());
        $this->flush();
        self::assertSame([], $this->frames());
        Database::transactionRollback();
        $this->flush();
        self::assertSame([], $this->frames());
    }

    public function testLeavingOrChangingTheSubscriptionDropsBufferedFacts(): void
    {
        $this->recordBothSources();
        Hilos::$sr->unsubscribeFromPage(
            AbstractHilosI18nPage::PAGE,
            new WebSocketPageUnsubscribeSignalDTO('overview', AbstractHilosI18nPage::PAGE),
        );
        $this->flush();
        self::assertSame([], $this->frames());

        $this->subscribe();
        $this->recordBothSources();
        Hilos::$sr->subscribeToPage('elsewhere', new WebSocketPageSubscribeSignalDTO('overview', 'elsewhere'));
        $this->flush();
        self::assertSame([], $this->frames());
    }

    /** @return iterable<string, array{string}> Subscription identities replaced during a guard */
    public static function departedSubscriptions(): iterable
    {
        yield 'leave' => ['leave'];
        yield 'another page' => ['another'];
        yield 'same page new subscription' => ['replace'];
        yield 'changed params' => ['params'];
    }

    /** @param string $departure How the subscription changes while the guard is running */
    #[DataProvider('departedSubscriptions')]
    public function testAStaleGuardVerdictCannotDeliverToAChangedSubscription(string $departure): void
    {
        $this->browser->duringIdentity = static function () use ($departure): void {
            if ($departure === 'leave') {
                Hilos::$sr->unsubscribeFromPage(
                    AbstractHilosI18nPage::PAGE,
                    new WebSocketPageUnsubscribeSignalDTO('overview', AbstractHilosI18nPage::PAGE),
                );
            } elseif ($departure === 'params') {
                Hilos::$sr->updatePageSubscription(
                    AbstractHilosI18nPage::PAGE,
                    new WebSocketPageUpdateSubscriptionSignalDTO('overview', AbstractHilosI18nPage::PAGE, ['changed' => 'yes']),
                );
            } else {
                $page = $departure === 'another' ? 'elsewhere' : AbstractHilosI18nPage::PAGE;
                Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('overview', $page));
            }
        };
        $this->recordBothSources();
        $this->flush();

        self::assertSame([], $this->frames());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    public function testRightsRefuseANonadminAndAGuestUntilViewModeAllowsTheCompleteAnswer(): void
    {
        $this->browser->admin = false;
        foreach ([1, null] as $userId) {
            $this->browser->userId = $userId;
            $this->recordBothSources();
            $this->flush();
            self::assertSame([], $this->frames());
        }
        $this->browser->viewer = true;
        $this->languageObjects[11]->nativeName = 'Current English';
        $this->recordBothSources();
        $this->flush();
        $this->assertOverview(1, 2, 1, 2, 1, 1);
    }

    public function testDeliveryFailureIsSilentOnFurtherProbesAndRecoversOnTheNextOwnFact(): void
    {
        $this->languageObjects->clearInMemory();
        $this->recordBothSources();
        $this->flush();
        $frames = $this->frames();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]->signalName->getName());
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frames[0]->data->data);
        self::assertSame(500, $frames[0]->data->data->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));

        $this->recordBothSources();
        $this->browser->recordDatabaseBack();
        $this->flush();
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));

        $this->language(11, 'en', false);
        $this->languageObjects[11]->nativeName = 'Current English';
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::locales, '1', []));
        $this->flush();
        self::assertSame([], $this->frames());
        $this->recordBothSources();
        $this->flush();
        $this->assertOverview(0, 1, 1, 2, 1, 1);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    public function testADebtBeforeTheFirstAnswerNeedsNoTableBindingsAndDatabaseBackStillWorks(): void
    {
        Hilos::$sr->markPageDeliveryFailure('overview');
        $this->browser->admin = false;
        $this->recordBothSources();
        $this->flush();
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        $this->browser->admin = true;
        $this->languageObjects[11]->nativeName = 'Current English';
        $this->recordBothSources();
        $this->flush();
        $this->assertOverview(1, 2, 1, 2, 1, 1);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));

        Hilos::$sr->markPageDeliveryFailure('overview');
        $this->browser->recordDatabaseBack();
        $this->flush();
        $this->assertOverview(1, 2, 1, 2, 1, 1);
    }

    private function subscribe(): void
    {
        Hilos::$sr->subscribeToPage(
            AbstractHilosI18nPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('overview', AbstractHilosI18nPage::PAGE),
        );
        Hilos::$sr->bindPageAgent('overview', AbstractHilosI18nPage::PAGE, HilosAgentType::HILOS_I18N_LIBRARY, null);
    }

    private function recordBothSources(): void
    {
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::languages, '11', []));
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::countries, '21', []));
    }

    private function flush(): void
    {
        self::assertSame([], $this->browser->flushToSignalRouter());
    }

    /** @return list<SignalDTO> All frames, without filtering duplicate or unexpected deliveries */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

    /**
     * @param int $enabledLanguages Enabled languages
     * @param int $totalLanguages Installed languages
     * @param int $enabledCountries Enabled countries
     * @param int $totalCountries Installed countries
     * @param int $builtIn Built-in countries
     * @param int $own Custom countries
     */
    private function assertOverview(
        int $enabledLanguages,
        int $totalLanguages,
        int $enabledCountries,
        int $totalCountries,
        int $builtIn,
        int $own,
    ): void
    {
        $frames = $this->frames();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
        self::assertSame('overview', $frames[0]->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]->data->data);
        $response = $frames[0]->data->data;
        self::assertSame(AbstractHilosI18nPage::PAGE, $response->pageKey);
        self::assertSame([
            'languages' => [
                'enabledCount' => $enabledLanguages, 'totalCount' => $totalLanguages,
                'defaultLanguageCode' => 'en', 'defaultLanguageName' => 'Current English',
            ],
            'countries' => [
                'enabledCount' => $enabledCountries, 'totalCount' => $totalCountries,
                'builtInCount' => $builtIn, 'ownCount' => $own,
            ],
        ], $response->payload->data[I18nOverviewData::DATA]);
        self::assertSame('Internationalization', $response->payload->data['pageLabel']);
        self::assertSame(AbstractHilosI18nPage::PAGE, $response->payload->data['pageHeadOwner']['page']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
        ], $response->payload->data['pageBreadcrumb']);
        self::assertSame([], $response->payload->data['pageChildren']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Overview'],
        ], $response->payload->data['pageTabs']);
        self::assertSame($response->toArray(), PageResponseSignalData::fromArray($response->toArray())->toArray());
    }

    /**
     * @param int $id Fixture id
     * @param string $code External language code
     * @param bool $enabled Switch state
     */
    private function language(int $id, string $code, bool $enabled): void
    {
        $entity = new EntityLanguage();
        $entity->id = $id;
        $entity->code = $code;
        $entity->native_name = 'English';
        $entity->enabled = $enabled;
        $this->languageObjects[$id] = ObjectLanguage::fromEntity($entity);
    }

    /**
     * @param int $id Fixture id
     * @param string $code Country code
     * @param bool $enabled Switch state
     */
    private function country(int $id, string $code, bool $enabled): void
    {
        $entity = new EntityCountry();
        $entity->id = $id;
        $entity->code = $code;
        $entity->currency_symbol = '¤';
        $entity->currency_code = 'XXX';
        $entity->enabled = $enabled;
        $this->countryObjects[$id] = ObjectCountry::fromEntity($entity);
    }
}

abstract class I18nOverviewFanoutHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_I18N => I18nOverviewFanoutPage::class];
}

final class I18nOverviewFanoutPage extends AbstractHilosI18nPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}

/** Mutable identity verdicts allow each live delivery to exercise the normal access gate. */
final class I18nOverviewFanoutBrowser extends BrowserContext
{
    public bool $admin = true;
    public bool $viewer = false;
    public ?int $userId = 1;
    public ?Closure $duringIdentity = null;

    /**
     * @param string $acceptKey Connection being judged
     * @return ConnectionIdentity Current fixture identity
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        $callback = $this->duringIdentity;
        $this->duringIdentity = null;
        $callback?->__invoke();
        return ConnectionIdentity::resolved($this->userId);
    }

    /**
     * @param int $userId Person being judged
     * @return bool Current privilege verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }

    /**
     * @param string $pageClass Page class
     * @param string $acceptKey Subscriber
     * @return bool Current view-mode verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

/** Uses WorkerManager's real serving-agent lookup and execution frame without starting its loop. */
final class I18nOverviewFanoutWorker extends WorkerManager
{
    /** @return SignalRouter Router bound to this test's topology */
    protected function createSignalRouter(): SignalRouter
    {
        return new I18nOverviewFanoutRouter();
    }

    /** @return AgentManager Holds the real library, without running catalog reflow */
    protected function createAgentManager(): AgentManager
    {
        $manager = new I18nOverviewFanoutAgentManager();
        $agent = new I18nLibraryAgent();
        $manager->addAgent($agent->getId(), $agent);
        return $manager;
    }

    /**
     * @param AgentInterface $agent Serving library
     * @return PageSignalRouter Real page builder and access verdict
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        assert($agent instanceof PageAgentInterface);
        return new PageSignalRouter(new HilosPageFactory($agent, I18nOverviewFanoutHilos::class), new ActionRouteConfig());
    }
}

final class I18nOverviewFanoutAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Never returns, only the pre-registered library is served
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new AgentCreationFailedException($agentType, $agentIndex);
    }
}

final class I18nOverviewFanoutRouter extends SignalRouter
{
    /** @return class-string<Hilos> Current test facade */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}
