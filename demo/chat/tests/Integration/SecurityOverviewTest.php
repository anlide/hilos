<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Security\SecurityPage;
use Demo\Chat\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Auth\Method\AuthMethodSettings;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Auth\SecondFactor\SecondFactorSettings;
use Hilos\Auth\StepUp\StepUpSettings;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
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
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Pages\Security\DTO\HilosOAuthProviderHeadData;
use Hilos\Pages\Security\DTO\HilosSecurityOverviewData;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** First subscription answers through the Chat worker, with persisted settings and OAuth credentials. */
final class SecurityOverviewTest extends IntegrationTestCase
{
    private SecurityOverviewServingWorker $worker;
    private SettingsLibraryAgent $settingsWriter;
    /** @var array<string, string|false> Environment values restored after each case */
    private array $previousEnvironment = [];
    /** @var array<string, array{exists: bool, value: mixed}> Original typed setting overrides */
    private array $previousSettings = [];
    /** @var array<string, array{exists: bool, clientId: ?string, secret: ?string}> Original provider fields */
    private array $previousProviders = [];
    /** @var list<int> People created for the two connection identities */
    private array $userIds = [];
    /** @var list<int> Sessions created for the two connection identities */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = new SecurityOverviewServingWorker(1);
        $this->settingsWriter = new SettingsLibraryAgent();
        Hilos::initBrowser();
        Hilos::$browser->bindPageResender($this->worker);
        TruthSourceRegistry::register(HilosDbContext::settings, TruthSourceKeys::all(), $this->settingsWriter->getId());
        TruthSourceRegistry::register(HilosDbContext::oauthProviders, TruthSourceKeys::all(), $this->worker->agent()->getId());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->worker->agent()->getId());
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        foreach ([
            ChatEnvConstants::OAUTH_GITHUB_CLIENT_ID, ChatEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
            ChatEnvConstants::OAUTH_GOOGLE_CLIENT_ID, ChatEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
        ] as $key) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key . '=');
        }
        $this->writeSettings([
            SecondFactorSettings::ENABLED_KEY => false,
            SecondFactorSettings::REQUIRED_KEY => 'admins',
            StepUpSettings::DISABLED_KEY => implode(',', Hilos::stepUpOperationDirectoryClass()::keys()),
            StepUpSettings::ENABLED_KEY => '',
            AuthMethodSettings::DISABLED_KEY => 'magic_link,sms',
            ImpersonationSettings::ALLOWED_KEY => false,
            ImpersonationSettings::SCOPE_KEY => 'view',
        ]);
        $this->underAgent($this->worker->agent(), function (): void {
            foreach ([OAuthProviderPreset::GITHUB->value => true, OAuthProviderPreset::GOOGLE->value => false] as $key => $complete) {
                $exists = isset(Hilos::$db->oauthProviders[$key]);
                $this->previousProviders[$key] = [
                    'exists' => $exists,
                    'clientId' => $exists ? Hilos::$db->oauthProviders[$key]->clientId : null,
                    'secret' => $exists ? Hilos::$db->oauthProviders[$key]->readClientSecret() : null,
                ];
                $provider = $exists ? Hilos::$db->oauthProviders[$key] : Hilos::$db->oauthProviders->actions->add($key);
                $provider->actions->updateClientId('overview-client');
                $provider->actions->writeClientSecret($complete ? 'overview-secret' : null);
            }
        });
        foreach (['security-admin' => true, 'security-viewer' => false] as $acceptKey => $admin) {
            $user = Hilos::$db->users->actions->createWithName($acceptKey);
            $this->userIds[] = (int) $user->id;
            if ($admin) {
                $user->actions->setAdmin(true);
            }
            $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->name() . $acceptKey), 0, 32));
            $this->sessionIds[] = (int) $session->id;
            $session->actions->bindUser((int) $user->id);
            $this->underAgent($this->worker->agent(), static fn () => Hilos::$rt->connections->actions->register(
                $acceptKey, (int) $user->id, $session->token, (int) $session->id,
            ));
        }
        $this->frames();
    }

    protected function tearDown(): void
    {
        foreach (['security-admin', 'security-viewer'] as $acceptKey) {
            $this->underAgent($this->worker->agent(), static fn () => Hilos::$rt->connections[$acceptKey]?->actions->unregister());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
        }
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->underAgent($this->settingsWriter, function (): void {
            JournalReceiptScope::run(JournalReceiptData::agent('security-overview-cleanup', $this->settingsWriter->getId()), function (): void {
                foreach ($this->previousSettings as $key => $previous) {
                    if ($previous['exists']) {
                        Hilos::$db->settings[$key]->actions->updateValue($previous['value']);
                    } else {
                        Hilos::$db->settings[$key]->actions->delete();
                    }
                }
            });
        });
        $this->underAgent($this->worker->agent(), function (): void {
            foreach ($this->previousProviders as $key => $previous) {
                if ($previous['exists']) {
                    Hilos::$db->oauthProviders[$key]->actions->updateClientId($previous['clientId']);
                    Hilos::$db->oauthProviders[$key]->actions->writeClientSecret($previous['secret']);
                } else {
                    // Provider actions expose field writes only; remove newly created fixture rows in storage.
                    Database::sql('DELETE FROM hilos_oauth_provider WHERE provider_key = ?', [$key]);
                }
            }
            Hilos::$db->mountedObjectCollection(HilosDbContext::oauthProviders)?->reHydrate();
        });
        foreach ($this->sessionIds as $id) {
            Hilos::$db->sessions[$id]->actions->delete();
        }
        foreach ($this->userIds as $id) {
            Hilos::$db->users[$id]->actions->delete();
        }
        TruthSourceRegistry::unregisterAgent($this->settingsWriter->getId());
        TruthSourceRegistry::unregisterAgent($this->worker->agent()->getId());
        RtTruthSourceRegistry::unregisterAgent($this->worker->agent()->getId());
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        Hilos::initBrowser();
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testRootFirstAnswerCarriesEveryTileAndIdentityForAdminAndViewer(): void
    {
        foreach (['security-admin', 'security-viewer'] as $acceptKey) {
            $response = $this->subscribe($acceptKey, SecurityPage::PAGE);
            self::assertSame(SecurityPage::PAGE, $response->pageKey);
            self::assertSame([
                'twoFactor' => ['enabled' => false, 'required' => 'admins'],
                'stepUp' => ['enabledCount' => 0, 'totalCount' => 15],
                'oauth' => ['configuredCount' => 1, 'totalCount' => 2, 'unconfigured' => [
                    ['providerKey' => 'oauth:google', 'label' => 'Google', 'missingFields' => 1],
                ]],
                'signInMethods' => ['enabledCount' => 4, 'totalCount' => 6, 'unready' => []],
                'impersonation' => ['allowed' => false, 'scope' => 'view'],
            ], $response->payload->data[HilosSecurityOverviewData::DATA]);
            self::assertSame('Security Center', $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
            self::assertSame([], $response->payload->windows);
            self::assertSame([], $response->payload->data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);
            self::assertSame([
                'page' => HilosPageConstants::HILOS_SECURITY,
                'label' => 'Security Center',
                'lead' => 'Sign-in methods, two-factor authentication and OAuth login providers.',
                'icon' => 'bi-shield-lock',
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_SECURITY_2FA, 'label' => 'Two-factor'],
                ['page' => HilosPageConstants::HILOS_SECURITY_STEP_UP, 'label' => 'Operations that ask for confirmation'],
                ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
                ['page' => HilosPageConstants::HILOS_SECURITY_SIGN_IN_METHODS, 'label' => 'Sign-in methods'],
                ['page' => HilosPageConstants::HILOS_SECURITY_IMPERSONATION, 'label' => 'Impersonation'],
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_TABS]);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($response->toArray(), JSON_THROW_ON_ERROR));
            $this->assertNoCredentials($response);
        }
    }

    /** @return iterable<string, array{?string, ?array<string, mixed>}> Known, incomplete, unknown and absent route targets */
    public static function providerHeads(): iterable
    {
        yield 'configured' => ['oauth:github', ['providerKey' => 'oauth:github', 'label' => 'GitHub', 'configured' => true, 'missingFields' => 0]];
        yield 'incomplete' => ['oauth:google', ['providerKey' => 'oauth:google', 'label' => 'Google', 'configured' => false, 'missingFields' => 1]];
        yield 'unknown' => ['oauth:unknown', null];
        yield 'absent' => [null, null];
    }

    /**
     * @param ?string $providerId Provider route parameter
     * @param ?array<string, mixed> $head Complete expected first head
     */
    #[DataProvider('providerHeads')]
    public function testProviderFirstAnswerKeepsItsRouteAndHeadForAdminAndViewer(?string $providerId, ?array $head): void
    {
        foreach (['security-admin', 'security-viewer'] as $acceptKey) {
            $params = $providerId === null ? [] : ['providerId' => $providerId];
            $response = $this->subscribe($acceptKey, SecurityOAuthProviderPage::PAGE, $params);
            self::assertSame(SecurityOAuthProviderPage::PAGE, $response->pageKey);
            self::assertSame($head, $response->payload->data[HilosOAuthProviderHeadData::DATA]);
            self::assertSame($params, Hilos::$sr->pageSubscription($acceptKey)?->params);
            self::assertSame('OAuth provider', $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_SECURITY, 'label' => 'Security Center'],
                ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH, 'label' => 'OAuth providers'],
                ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER, 'label' => 'OAuth provider'],
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
            self::assertSame([
                'page' => HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER,
                'label' => 'OAuth provider',
                'lead' => 'A single OAuth provider configuration.',
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER, 'label' => 'Overview'],
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_TABS]);
            self::assertSame([], $response->payload->data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);
            self::assertArrayHasKey(ChatTableContext::hilosSecurityOauthProviderFields, $response->payload->windows);
            $this->assertNoCredentials($response);
        }
    }

    /** @param array<string, bool|string> $values Typed persisted overrides */
    private function writeSettings(array $values): void
    {
        $this->underAgent($this->settingsWriter, function () use ($values): void {
            JournalReceiptScope::run(
                JournalReceiptData::agent('security-overview-fixture', $this->settingsWriter->getId()),
                function () use ($values): void {
                    foreach ($values as $key => $value) {
                        $exists = isset(Hilos::$db->settings[$key]);
                        $this->previousSettings[$key] = [
                            'exists' => $exists,
                            'value' => $exists ? Hilos::$setting->effectiveValueFor($key) : null,
                        ];
                        if ($exists) {
                            Hilos::$db->settings[$key]->actions->updateValue($value);
                        } else {
                            Hilos::$db->settings->actions->add($key, $value, Hilos::$setting->catalog());
                        }
                    }
                },
            );
        });
    }

    /**
     * @param string $acceptKey Connection to subscribe
     * @param string $page Page key
     * @param array<string, mixed> $params Route params
     * @return PageResponseSignalData The single complete page response
     */
    private function subscribe(string $acceptKey, string $page, array $params = []): PageResponseSignalData
    {
        $this->worker->subscribe($acceptKey, $page, $params);
        $frames = $this->frames();
        $responses = [];
        foreach ($frames as $frame) {
            if (!$frame->data instanceof WebSocketSignalData || $frame->data->targetAcceptKey !== $acceptKey) {
                continue;
            }
            self::assertContains($frame->signalName->getName(), [SignalTypeConstants::RT_STALENESS, SignalTypeConstants::PAGE_RESPONSE]);
            if ($frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(PageResponseSignalData::class, $frame->data->data);
                $responses[] = $frame->data->data;
            }
        }
        self::assertCount(1, $responses);
        self::assertSame($responses[0]->toArray(), PageResponseSignalData::fromArray($responses[0]->toArray())->toArray());
        return $responses[0];
    }

    /** @return list<SignalDTO> Every queued frame */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

    /** @param PageResponseSignalData $response Complete first page frame */
    private function assertNoCredentials(PageResponseSignalData $response): void
    {
        $data = json_encode($response->payload->data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('overview-client', $data);
        self::assertStringNotContainsString('overview-secret', $data);
        self::assertStringNotContainsString('clientSecret', $data);
    }
}

/** Real Chat routing and page builders, served by the index agent without starting a worker loop. */
final class SecurityOverviewServingWorker extends WorkerManager
{
    private DemoHilosAgent $agent;

    /** @return DemoHilosAgent Agent serving the security pages */
    public function agent(): DemoHilosAgent
    {
        return $this->agent;
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param string $page Page key
     * @param array<string, mixed> $params Route parameters
     */
    public function subscribe(string $acceptKey, string $page, array $params): void
    {
        $agentId = ExecutionContext::currentAgentId();
        $currentAcceptKey = ExecutionContext::currentAcceptKey();
        try {
            $this->handleDaemonMessage(new DaemonAgentMessageDTO(
                $this->agent->getId(),
                new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                    new SignalName($page), new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params),
                ),
            ));
        } finally {
            ExecutionContext::setCurrentAgentId($agentId);
            ExecutionContext::setCurrentAcceptKey($currentAcceptKey);
        }
    }

    /** @return SignalRouter Chat's real subscription and source routing */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /** @return AgentManager Manager holding the real serving agent */
    protected function createAgentManager(): AgentManager
    {
        $this->agent = new DemoHilosAgent();
        $manager = new SecurityOverviewServingAgentManager();
        $manager->addAgent($this->agent->getId(), $this->agent);
        return $manager;
    }

    /**
     * @param AgentInterface $agent Serving agent
     * @return PageSignalRouter Router over the real Chat page catalog and builders
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        assert($agent instanceof PageAgentInterface);
        return new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
    }
}

final class SecurityOverviewServingAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested agent type
     * @param ?string $agentIndex Requested agent index
     * @return AgentInterface Never returns, only the registered index agent is served
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new AgentCreationFailedException($agentType, $agentIndex);
    }
}
