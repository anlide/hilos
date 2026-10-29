<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Impersonation\ImpersonationSettingsCatalog;
use Hilos\Auth\Method\AuthMethodDirectory;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\Method\AuthMethodSettingsCatalog;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpOperation;
use Hilos\Auth\StepUp\StepUpOperationDirectory;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\AccountDeletions;
use Hilos\Database\View\Collection\OAuthProviders;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Pages\Security\AbstractHilosSecurityPage;
use Hilos\Pages\Security\DTO\HilosSecurityOverviewData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real worker re-send, page verdict, builder and wire; only the persisted row storage is in memory. */
final class SecurityOverviewFanoutTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?BrowserContext $previousBrowser;
    private string $previousHilosClass;
    private ?SettingsAccessor $previousSettings;
    private ObjectSettings $settingObjects;
    private SecurityOverviewFanoutSettings $settings;
    private bool $unavailable = false;
    private bool $secretSet = false;
    private ?string $clientId = null;
    private SecurityOverviewFanoutBrowser $browser;
    private SecurityOverviewFanoutWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousHilosClass = Hilos::appClass();
        $this->previousSettings = Hilos::$setting;
        Hilos::$env = new EnvAccessor();
        Hilos::$setting = $this->settings = new SecurityOverviewFanoutSettings();
        $this->browser = new SecurityOverviewFanoutBrowser();
        SecurityOverviewFanoutHilos::initBrowser($this->browser);
        $this->worker = new SecurityOverviewFanoutWorker(1);
        $this->browser->bindPageResender($this->worker);
        $this->settingObjects = ObjectSettings::initEmpty();
        $settings = Settings::init();
        $settings->setObjectCollection($this->settingObjects);
        $provider = $this->createMock(OAuthProvider::class);
        $provider->method('__get')->willReturnCallback(fn (string $key): mixed => $key === 'clientId' ? $this->clientId : null);
        $provider->method('hasClientSecret')->willReturnCallback(fn (): bool => $this->secretSet);
        $provider->expects(self::never())->method('readClientSecret');
        $providers = $this->createStub(OAuthProviders::class);
        $providers->method('offsetExists')->willReturn(true);
        $providers->method('offsetGet')->willReturn($provider);
        $deletions = $this->createStub(AccountDeletions::class);
        $merges = $this->createStub(UserMerges::class);
        $users = $this->createStub(Users::class);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnCallback(function (string $key) use (
            $settings, $providers, $deletions, $merges, $users,
        ): mixed {
            // The access gate also reads settings before entering the serving agent.
            if ($key === HilosDbContext::oauthProviders
                || ($key === HilosDbContext::settings && ExecutionContext::currentAgentId() !== null)
            ) {
                self::assertSame(HilosAgentType::HILOS_SETTINGS_LIBRARY, ExecutionContext::currentAgentId());
                self::assertSame('overview', ExecutionContext::currentAcceptKey());
                if ($this->unavailable) {
                    throw new DatabaseException('Security sources unavailable');
                }
            }
            return match ($key) {
                HilosDbContext::settings => $settings,
                HilosDbContext::oauthProviders => $providers,
                HilosDbContext::accountDeletions => $deletions,
                HilosDbContext::userMerges => $merges,
                HilosDbContext::users => $users,
                default => null,
            };
        });
        $this->subscribe();
    }

    protected function tearDown(): void
    {
        Database::transactionRollback();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('overview'));
        Hilos::$setting = $this->previousSettings;
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
        foreach (AbstractHilosSecurityPage::READS_DB as $source) {
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
        $this->setting(SecondFactorSettings::REQUIRED_KEY, 'everyone');
        $this->clientId = 'stored-client';
        $this->secretSet = true;
        $this->browser->record(new SourceChange(SourceChange::KIND_DB, $source, '99', $mutation));
        $this->flush();
        $this->assertOverview(required: 'everyone', configured: true);
        $this->flush();
        self::assertSame([], $this->frames());
    }

    public function testBothSourcesAndManyRowsAreFoldedIntoOneWholeAnswerPerFlush(): void
    {
        $this->setting(SecondFactorSettings::REQUIRED_KEY, 'admins');
        $this->clientId = 'current-client';
        $this->secretSet = true;
        $this->recordBothSources();
        // A transport override is still an own fact, regardless of its key.
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::settings, 'transport-setting', []));
        $this->browser->recordDatabaseBack();
        $this->flush();
        $this->assertOverview(required: 'admins', configured: true);
    }

    public function testForeignDatabaseSourcesAndRuntimeNamesakesDoNotRedeliver(): void
    {
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::locales, '1', []));
        $this->browser->record(SourceChange::rtUpdated(HilosDbContext::settings, '11', []));
        $this->browser->record(SourceChange::rtUpdated(HilosDbContext::oauthProviders, '21', []));
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

        Database::transactionStart();
        Database::afterCommit(fn () => $this->recordBothSources());
        $this->flush();
        self::assertSame([], $this->frames());
        Database::transactionCommit();
        $this->flush();
        $this->assertOverview();
    }

    public function testLeavingOrChangingTheSubscriptionDropsBufferedFacts(): void
    {
        $this->recordBothSources();
        Hilos::$sr->unsubscribeFromPage(
            AbstractHilosSecurityPage::PAGE,
            new WebSocketPageUnsubscribeSignalDTO('overview', AbstractHilosSecurityPage::PAGE),
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
                    AbstractHilosSecurityPage::PAGE,
                    new WebSocketPageUnsubscribeSignalDTO('overview', AbstractHilosSecurityPage::PAGE),
                );
            } elseif ($departure === 'params') {
                Hilos::$sr->updatePageSubscription(
                    AbstractHilosSecurityPage::PAGE,
                    new WebSocketPageUpdateSubscriptionSignalDTO('overview', AbstractHilosSecurityPage::PAGE, ['changed' => 'yes']),
                );
            } else {
                $page = $departure === 'another' ? 'elsewhere' : AbstractHilosSecurityPage::PAGE;
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
        $this->settings->closedKeys = [SecondFactorSettings::REQUIRED_KEY, AuthMethodSettings::DISABLED_KEY];
        $this->recordBothSources();
        $this->flush();
        $this->assertOverview(viewer: true);
        $this->browser->admin = true;
        $this->browser->viewer = false;
        $this->browser->userId = 1;
        $this->recordBothSources();
        $this->flush();
        $this->assertOverview();
    }

    public function testDeliveryFailureIsSilentOnFurtherProbesAndRecoversOnTheNextOwnFact(): void
    {
        $this->unavailable = true;
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

        $this->unavailable = false;
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::locales, '1', []));
        $this->flush();
        self::assertSame([], $this->frames());
        $this->recordBothSources();
        $this->flush();
        $this->assertOverview();
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    /** @return iterable<string, array{bool}> Both eligible facts recover a failed first answer */
    public static function recoveryFacts(): iterable
    {
        yield 'own source' => [false];
        yield 'database back' => [true];
    }

    /** @param bool $databaseBack Whether the database-back fact replaces an own-source fact */
    #[DataProvider('recoveryFacts')]
    public function testADebtBeforeTheFirstAnswerNeedsNoTableBindingsAndDatabaseBackStillWorks(bool $databaseBack): void
    {
        $this->unavailable = true;
        $this->worker->subscribe();
        $frames = $this->frames();
        self::assertNotContains(
            SignalTypeConstants::PAGE_RESPONSE,
            array_map(static fn (SignalDTO $frame): string => $frame->signalName->getName(), $frames),
        );
        $errors = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalConstants::SUBSCRIPTION_PAGE_ERROR));
        self::assertCount(1, $errors);
        self::assertSame(500, $errors[0]->data->data->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        $this->unavailable = false;
        $this->browser->admin = false;
        $this->recordBothSources();
        $this->flush();
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('overview'));
        $this->browser->admin = true;
        if ($databaseBack) {
            $this->browser->recordDatabaseBack();
        } else {
            $this->recordBothSources();
        }
        $this->flush();
        $this->assertOverview();
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('overview'));
    }

    private function subscribe(): void
    {
        Hilos::$sr->subscribeToPage(
            AbstractHilosSecurityPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('overview', AbstractHilosSecurityPage::PAGE),
        );
        Hilos::$sr->bindPageAgent('overview', AbstractHilosSecurityPage::PAGE, HilosAgentType::HILOS_SETTINGS_LIBRARY, null);
    }

    private function recordBothSources(): void
    {
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::settings, '11', []));
        $this->browser->record(SourceChange::dbUpdated(HilosDbContext::oauthProviders, '21', []));
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
     * @param string $required Current policy requirement
     * @param bool $configured Whether the stored provider has both credentials
     * @param bool $viewer Whether selected setting-derived values must be hidden
     */
    private function assertOverview(string $required = 'none', bool $configured = false, bool $viewer = false): void
    {
        $frames = $this->frames();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
        self::assertSame('overview', $frames[0]->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]->data->data);
        $response = $frames[0]->data->data;
        self::assertSame(AbstractHilosSecurityPage::PAGE, $response->pageKey);
        self::assertSame([
            'twoFactor' => ['enabled' => true, 'required' => $viewer ? HiddenValue::mark() : $required],
            'stepUp' => ['enabledCount' => 1, 'totalCount' => 1],
            'oauth' => ['configuredCount' => (int) $configured, 'totalCount' => 1, 'unconfigured' => $configured ? [] : [
                ['providerKey' => 'oauth:github', 'label' => 'GitHub', 'missingFields' => 2],
            ]],
            'signInMethods' => [
                'enabledCount' => $viewer ? HiddenValue::mark() : 2, 'totalCount' => 2,
                'unready' => $viewer ? HiddenValue::mark() : [],
            ],
            'impersonation' => ['allowed' => true, 'scope' => 'act'],
        ], $response->payload->data[HilosSecurityOverviewData::DATA]);
        self::assertSame('Security Center', $response->payload->data['pageLabel']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
        ], $response->payload->data['pageBreadcrumb']);
        self::assertSame([], $response->payload->data['pageChildren']);
        self::assertSame([], $response->payload->windows);
        self::assertSame($response->toArray(), PageResponseSignalData::fromArray($response->toArray())->toArray());
    }

    /**
     * @param string $key Setting key
     * @param string $value Persisted override
     */
    private function setting(string $key, string $value): void
    {
        $entity = new EntitySetting();
        $entity->id = 11;
        $entity->key = $key;
        $entity->value = $value;
        $this->settingObjects[11] = ObjectSetting::fromEntity($entity);
    }

}

abstract class SecurityOverviewFanoutHilos extends Hilos
{
    public const array PAGES = [HilosPageConstants::HILOS_SECURITY => SecurityOverviewFanoutPage::class];
    protected const string AUTH_METHOD_DIRECTORY = SecurityOverviewFanoutMethods::class;
    protected const string OAUTH_PROVIDER_DIRECTORY = SecurityOverviewFanoutProviders::class;
    protected const string STEP_UP_OPERATION_DIRECTORY = SecurityOverviewFanoutOperations::class;
}

final class SecurityOverviewFanoutPage extends AbstractHilosSecurityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_SETTINGS_LIBRARY;
}

/** Mutable identity verdicts allow each live delivery to exercise the normal access gate. */
final class SecurityOverviewFanoutBrowser extends BrowserContext
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
final class SecurityOverviewFanoutWorker extends WorkerManager
{
    /** Deliver the initial subscription through the same agent-message entry as production. */
    public function subscribe(): void
    {
        $agentId = ExecutionContext::currentAgentId();
        $acceptKey = ExecutionContext::currentAcceptKey();
        try {
            $this->handleDaemonMessage(new DaemonAgentMessageDTO(
                HilosAgentType::HILOS_SETTINGS_LIBRARY,
                new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                    new SignalName(AbstractHilosSecurityPage::PAGE),
                    new WebSocketPageSubscribeSignalDTO('overview', AbstractHilosSecurityPage::PAGE),
                ),
            ));
        } finally {
            ExecutionContext::setCurrentAgentId($agentId);
            ExecutionContext::setCurrentAcceptKey($acceptKey);
        }
    }

    /** @return SignalRouter Router bound to this test's topology */
    protected function createSignalRouter(): SignalRouter
    {
        return new SecurityOverviewFanoutRouter();
    }

    /** @return AgentManager Holds the real library, without running catalog reflow */
    protected function createAgentManager(): AgentManager
    {
        $manager = new SecurityOverviewFanoutAgentManager();
        $agent = new SettingsLibraryAgent();
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
        return new PageSignalRouter(new HilosPageFactory($agent, SecurityOverviewFanoutHilos::class), new ActionRouteConfig());
    }
}

final class SecurityOverviewFanoutAgentManager extends AgentManager
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

final class SecurityOverviewFanoutRouter extends SignalRouter
{
    /** @return class-string<Hilos> Current test facade */
    protected function hilosClass(): string
    {
        return Hilos::appClass();
    }
}

final class SecurityOverviewFanoutMethods extends AuthMethodDirectory
{
    /** @return list<string> Complete wired method set */
    protected static function methods(): array
    {
        return [AuthMethodKey::PASSWORD, OAuthProviderPreset::GITHUB->value];
    }
}

final class SecurityOverviewFanoutProviders extends OAuthProviderDirectory
{
    /** @return array<string, OAuthProviderDescriptor> Provider without environment credentials */
    protected static function providers(): array
    {
        return [OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'GitHub')];
    }
}

final class SecurityOverviewFanoutOperations extends StepUpOperationDirectory
{
    /** @return array<string, StepUpOperation> Complete operation set */
    protected static function operations(): array
    {
        return ['sensitive' => new StepUpOperation('sensitive', 'Sensitive', 'change security', false)];
    }
}

final class SecurityOverviewFanoutCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Domain catalogs with their real defaults and types */
    public static function getCatalog(): array
    {
        return array_replace(
            SecondFactorSettingsCatalog::getCatalog(), StepUpSettingsCatalog::getCatalog(),
            AuthMethodSettingsCatalog::getCatalog(), ImpersonationSettingsCatalog::getCatalog(),
        );
    }
}

final class SecurityOverviewFanoutSettings extends SettingsAccessor
{
    /** @var list<string> Settings closed to viewers */
    public array $closedKeys = [];

    public function __construct()
    {
        parent::__construct(SecurityOverviewFanoutCatalog::class);
    }

    /**
     * @param string $key Catalog key
     * @return bool Whether this setting is visible to the current viewer
     */
    public function visibleInAdminViewMode(string $key): bool
    {
        return !in_array($key, $this->closedKeys, true) && parent::visibleInAdminViewMode($key);
    }
}
