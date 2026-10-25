<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelConfigurationPage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Subscription assembly uses the real browser bridge and mounted tables without a database. */
final class CommunicationsChannelContextPageTest extends TestCase
{
    private ?HilosDbContext $previousDb = null;
    private ?EnvAccessor $previousEnv = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?BrowserContext $previousBrowser = null;
    private ?SignalRouter $previousRouter = null;
    private ?TableContext $previousTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousSetting = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRouter = Hilos::$sr;
        $this->previousTable = Hilos::$table;
        Hilos::$db = null;
        Hilos::$env = null;
        Hilos::$setting = new SettingsAccessor(ChannelSettingsCatalog::class);
        Hilos::$sr = new ChannelContextTestRouter();
        ChannelContextTestHilos::initBrowser(new ChannelContextTestBrowser());

        $fields = new HilosCommunicationsChannelFieldsTable();
        $deliveries = $this->createPartialMock(HilosNotificationDeliveriesTable::class, ['query']);
        $deliveries->method('query')->willReturn(new TableSnapshotDTO([], 0));
        Hilos::$table = $this->createStub(TableContext::class);
        Hilos::$table->method('get')->willReturnMap([
            [HilosCommunicationsChannelFieldsTable::TABLE, $fields],
            [HilosNotificationDeliveriesTable::TABLE, $deliveries],
        ]);
    }

    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        Hilos::$setting = $this->previousSetting;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$sr = $this->previousRouter;
        Hilos::$table = $this->previousTable;
        parent::tearDown();
    }

    /**
     * @param class-string<AbstractPage> $pageClass Channel tab
     * @param ?string $table Table mounted on that tab, or null for Configuration before activation
     */
    #[DataProvider('tabs')]
    public function testFirstAndUpdatedSubscriptionEachAnswerOnceWithSummaryIdentityAndTheirWindow(string $pageClass, ?string $table): void
    {
        $page = $this->page($pageClass);
        $params = new PageRouteParams(['channelId' => 'email']);
        foreach (['onSubscribe', 'onUpdateSubscription'] as $method) {
            $page->$method('channel-client', $params);
            $answer = $this->takeAnswer();

            self::assertSame($pageClass::PAGE, $answer->pageKey);
            self::assertSame('email', $answer->payload->data['channelSummary']['channel']);
            self::assertSame('Email', $answer->payload->data['channelSummary']['label']);
            self::assertSame('smtp', $answer->payload->data['channelSummary']['driver']);
            self::assertFalse($answer->payload->data['channelSummary']['enabled']);
            self::assertArrayHasKey('pageLabel', $answer->payload->data);
            self::assertArrayHasKey('pageBreadcrumb', $answer->payload->data);
            self::assertArrayNotHasKey('pageHeadOwner', $answer->payload->data);
            self::assertSame($table === null ? [] : [$table], array_keys($answer->payload->windows));
            self::assertSame([], $answer->payload->refusedWindows);
            if ($table === HilosCommunicationsChannelFieldsTable::TABLE) {
                self::assertNotEmpty($answer->payload->windows[$table]['rows']);
            }
            self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'The subscription must not send a second response.');
        }
    }

    /**
     * @param class-string<AbstractPage> $pageClass Channel tab
     * @param ?string $table Unused table binding from the shared tab matrix
     */
    #[DataProvider('tabs')]
    public function testUnknownChannelRefusesBeforeOpeningAnyWindowOrSendingAResponse(string $pageClass, ?string $table): void
    {
        $browser = $this->createMock(BrowserContext::class);
        $browser->expects(self::never())->method('buildSubscribeSnapshot');
        Hilos::$browser = $browser;
        foreach (['onSubscribe', 'onUpdateSubscription'] as $method) {
            try {
                $this->page($pageClass)->$method('channel-client', new PageRouteParams(['channelId' => 'missing']));
                self::fail('An unregistered channel must be refused.');
            } catch (PageResourceNotFoundException $exception) {
                self::assertSame(404, $exception->httpCode);
            }
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    /**
     * @param class-string<AbstractPage> $pageClass Channel tab
     * @param ?string $table Unused table binding from the shared tab matrix
     */
    #[DataProvider('tabs')]
    public function testMissingAndMalformedAddressesNeverAnswerWithAnEmptyChannel(string $pageClass, ?string $table): void
    {
        foreach ([[], ['channelId' => ''], ['channelId' => 7], ['channelId' => ['email']]] as $params) {
            foreach (['onSubscribe', 'onUpdateSubscription'] as $method) {
                try {
                    $this->page($pageClass)->$method('channel-client', new PageRouteParams($params));
                    self::fail('A missing string channelId must be refused.');
                } catch (MissingPageRouteParamException $exception) {
                    self::assertSame(400, $exception->httpCode);
                }
                self::assertNull(Hilos::$sr->getNextQueuedSignal());
            }
        }
    }

    /**
     * @param class-string<AbstractPage> $pageClass Channel tab
     * @param ?string $table Unused table binding from the shared tab matrix
     */
    #[DataProvider('tabs')]
    public function testViewerGetsOnlyEnablementHiddenByItsOwnSettingsVerdict(string $pageClass, ?string $table): void
    {
        $page = $this->page($pageClass);
        $params = new PageRouteParams(['channelId' => 'email']);
        $page->onSubscribe('channel-client', $params);
        $adminSummary = $this->takeAnswer()->payload->data['channelSummary'];

        Hilos::$browser->viewer = true;
        foreach ([true, false] as $shown) {
            Hilos::$setting = new ChannelContextTestSettings($shown);
            $page->onUpdateSubscription('channel-client', $params);
            $viewerSummary = $this->takeAnswer()->payload->data['channelSummary'];

            self::assertSame(
                array_replace($adminSummary, ['enabled' => $shown ? false : HiddenValue::mark()]),
                $viewerSummary,
            );
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    public function testConfigurationAddsNoMutationOrHandoverOwner(): void
    {
        self::assertSame([
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST,
        ], array_keys(ChannelContextTestChannelPage::ACTIONS));
        self::assertSame([
            HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE,
        ], array_keys(ChannelContextTestChannelPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]));
        self::assertSame([], ChannelContextTestConfigurationPage::ACTIONS);
        self::assertSame([], ChannelContextTestConfigurationPage::SIGNALS);
        self::assertSame([
            HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
        ], array_keys(ChannelContextTestDeliveriesPage::ACTIONS));
        self::assertSame([
            HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
        ], array_keys(ChannelContextTestDeliveriesPage::SIGNALS[SignalTypeConstants::AGENT_SIGNAL]));

        foreach ([
            ChannelContextTestChannelPage::class,
            ChannelContextTestConfigurationPage::class,
            ChannelContextTestDeliveriesPage::class,
        ] as $page) {
            self::assertSame(PageAccessLevel::ADMIN, $page::ACCESS_LEVEL);
            self::assertSame(PageReach::ROUTE, $page::REACH);
            self::assertSame([HilosDbContext::settings], $page::READS_DB);
        }
        self::assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            ChannelContextTestConfigurationPage::BROWSER[BrowserConfigKey::SIGNAL],
        );
        self::assertTrue(new HilosPageFactory($this->agent(), Hilos::class)->hasPage(ChannelContextTestConfigurationPage::PAGE));
    }

    /** @return iterable<string, array{class-string<AbstractPage>, ?string}> Current page bindings */
    public static function tabs(): iterable
    {
        yield 'legacy channel fields' => [ChannelContextTestChannelPage::class, HilosCommunicationsChannelFieldsTable::TABLE];
        yield 'configuration awaiting mount' => [ChannelContextTestConfigurationPage::class, null];
        yield 'delivery journal' => [ChannelContextTestDeliveriesPage::class, HilosNotificationDeliveriesTable::TABLE];
    }

    /**
     * @param class-string<AbstractPage> $pageClass Channel tab
     * @return AbstractPage Page served by the fixture agent
     */
    private function page(string $pageClass): AbstractPage
    {
        return new $pageClass($this->agent());
    }

    /** @return PageAgentInterface Page agent supplying the real signal source */
    private function agent(): PageAgentInterface
    {
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getId')->willReturn('channel-test-agent');
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'channel-test-agent'));
        return $agent;
    }

    /** @return PageResponseSignalData The single addressed page response */
    private function takeAnswer(): PageResponseSignalData
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertSame('channel-client', $signal->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        return $signal->data->data;
    }
}

final class ChannelContextTestChannelPage extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'channel-test-agent';
}

final class ChannelContextTestConfigurationPage extends AbstractHilosCommunicationsChannelConfigurationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'channel-test-agent';
}

final class ChannelContextTestDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'channel-test-agent';
}

abstract class ChannelContextTestHilos extends Hilos
{
    public const array PAGES = [
        ChannelContextTestChannelPage::PAGE => ChannelContextTestChannelPage::class,
        ChannelContextTestConfigurationPage::PAGE => ChannelContextTestConfigurationPage::class,
        ChannelContextTestDeliveriesPage::PAGE => ChannelContextTestDeliveriesPage::class,
    ];

    public const array PAGE_TABLES = [
        ChannelContextTestChannelPage::PAGE => [
            HilosCommunicationsChannelFieldsTable::TABLE => [],
        ],
        ChannelContextTestDeliveriesPage::PAGE => [HilosNotificationDeliveriesTable::TABLE => []],
    ];

    protected const string NOTIFICATION_CHANNEL_REGISTRY = ChannelContextTestRegistry::class;
}

final class ChannelContextTestRegistry extends DeliveryChannelRegistry
{
    /** @return array<string, MailDeliveryChannel> Code-registry fixture */
    protected static function channels(): array
    {
        return ['email' => new MailDeliveryChannel()];
    }
}

final class ChannelContextTestBrowser extends BrowserContext
{
    public bool $viewer = false;

    /**
     * @param string $pageClass Subscribed page class
     * @param string $acceptKey Subscribing connection
     * @return bool Fixture's viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

final class ChannelContextTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology */
    protected function hilosClass(): string
    {
        return ChannelContextTestHilos::class;
    }
}

final class ChannelContextTestSettings extends SettingsAccessor
{
    /** @param bool $shown Whether enablement is visible to a viewer */
    public function __construct(private readonly bool $shown)
    {
        parent::__construct(ChannelSettingsCatalog::class);
    }

    /**
     * @param string $key Settings catalog key
     * @return bool Fixture verdict for the channel's enablement key
     */
    public function visibleInAdminViewMode(string $key): bool
    {
        return $this->shown && $key === DeliveryChannelSettings::enabledKey('email');
    }
}
