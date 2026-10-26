<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications;

use Closure;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\SettingValue;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Collection\AccountDeletions;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Item\Setting;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelConfigurationPage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;
use Hilos\Mail\Frame\MailLetterFrameSettings;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;
use Hilos\Pages\Communications\CommunicationsChannelSummaryAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Real source bus, transaction boundary, subscription mirror and browser publication; no database connection. */
final class CommunicationsChannelSummaryAudienceTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?SettingsAccessor $previousSetting;
    private ?EnvAccessor $previousEnv;
    private ?BrowserContext $previousBrowser;
    private ?SignalRouter $previousRouter;
    private string $previousAppClass;
    private SummaryAudienceTestBrowser $browser;
    private SummaryAudienceTestAgent $agent;

    /** @var array<string, string> Persisted overrides, read by the real config resolver */
    private array $values = [];
    private bool $frameVisible = false;
    private int $frameReads = 0;

    /** @var array<string, int> Summary reads counted at the descriptor's fields seam */
    private array $reads = ['email' => 0, 'sms' => 0];

    /** @var array<string, Closure> Optional side effect or failure while a summary is being read */
    private array $onRead = [];

    /** Installs channel sources with no account verdict retained from another database fixture. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousEnv = Hilos::$env;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRouter = Hilos::$sr;
        $this->previousAppClass = Hilos::appClass();
        AccountStandingResolver::forgetAll();
        Hilos::$env = null;
        Hilos::$sr = new SignalRouter();
        $this->browser = new SummaryAudienceTestBrowser(1, true);
        SummaryAudienceTestHilos::initBrowser($this->browser);
        $this->agent = new SummaryAudienceTestAgent();
        SummaryAudienceTestRegistry::$channels = [];
        foreach (['email', 'sms'] as $channel) {
            $descriptor = $this->createStub(AbstractDeliveryChannel::class);
            $descriptor->method('name')->willReturn($channel);
            $descriptor->method('label')->willReturn(ucfirst($channel));
            $descriptor->method('driver')->willReturn('transport');
            $descriptor->method('enabledSettingKey')->willReturn(DeliveryChannelSettings::enabledKey($channel));
            $descriptor->method('configFields')->willReturnCallback(function () use ($channel): array {
                $this->reads[$channel]++;
                if (isset($this->onRead[$channel])) {
                    ($this->onRead[$channel])();
                }
                return [new ChannelConfigField('host', 'Host'), new ChannelConfigField('secret', 'Secret', secret: true)];
            });
            SummaryAudienceTestRegistry::$channels[$channel] = $descriptor;
        }
        $settings = $this->createStub(Settings::class);
        $settings->method('offsetExists')->willReturnCallback(fn (string $key): bool => isset($this->values[$key]));
        $settings->method('offsetGet')->willReturnCallback(function (string $key): Setting {
            if ($key === MailLetterFrameSettings::SETTING_KEY) {
                $this->frameReads++;
            }
            $row = $this->createStub(Setting::class);
            $row->method('__get')->willReturn($this->values[$key]);
            return $row;
        });
        $deletions = $this->createStub(AccountDeletions::class);
        $merges = $this->createStub(UserMerges::class);
        $users = $this->createStub(Users::class);
        Hilos::$db = $this->createStub(HilosDbContext::class);
        Hilos::$db->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            HilosDbContext::settings => $settings,
            HilosDbContext::accountDeletions => $deletions,
            HilosDbContext::userMerges => $merges,
            HilosDbContext::users => $users,
            default => null,
        });
        Hilos::$setting = $this->createStub(SettingsAccessor::class);
        Hilos::$setting->method('offsetExists')->willReturnCallback(static fn (string $key): bool =>
            str_starts_with($key, DeliveryChannelSettings::PREFIX));
        Hilos::$setting->method('offsetGet')->willReturnCallback(static fn (string $key): SettingValue => new SettingValue($key));
        Hilos::$setting->method('typeFor')->willReturnCallback(static fn (string $key): string =>
            str_ends_with($key, '.enabled') ? SettingsCatalogConstants::TYPE_BOOLEAN : SettingsCatalogConstants::TYPE_STRING);
        Hilos::$setting->method('effectiveValueFor')->willReturnCallback(fn (string $key): mixed => $this->values[$key] ?? false);
        Hilos::$setting->method('visibleInAdminViewMode')->willReturnCallback(fn (string $key): bool =>
            $key === DeliveryChannelSettings::enabledKey('sms') || ($this->frameVisible && $key === MailLetterFrameSettings::SETTING_KEY));
        CommunicationsChannelSummaryAudience::reset();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new CommunicationsChannelSummaryAudience());
    }

    /** Drops this fixture's account verdicts before restoring the process bindings. */
    protected function tearDown(): void
    {
        CommunicationsChannelSummaryAudience::reset();
        SourceChangeBus::reset();
        SummaryAudienceTestRegistry::$channels = [];
        AccountStandingResolver::forgetAll();
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        Hilos::$env = $this->previousEnv;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$sr = $this->previousRouter;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        parent::tearDown();
    }

    /**
     * @param SourceChange $change Settings mutation with no setting key in its diff
     * @param bool $remote Whether the fact came from applied remote sync
     */
    #[DataProvider('settingsFacts')]
    public function testOneFactServesAllTabsAndChannelsOnceAndIdleTicksReadNothing(SourceChange $change, bool $remote): void
    {
        $recipients = [];
        foreach (['email', 'sms'] as $channel) {
            foreach ($this->tabs($channel) as $index => $page) {
                $acceptKey = $channel . '-' . $index;
                $this->subscribe($acceptKey, $page, $channel);
                $recipients[$acceptKey] = [$page, $channel];
            }
        }
        $held = new TableViewportSubscription('held-table');
        Hilos::$sr->setTableViewport('email-0', $held);
        $this->tick();
        self::assertSame(['email' => 0, 'sms' => 0], $this->reads);
        self::assertSame([], $this->frames());
        $this->values[DeliveryChannelSettings::enabledKey('email')] = '1';
        $this->values[DeliveryChannelSettings::fieldKey('email', 'host')] = 'private-host';
        $publish = static fn () => SourceChangeBus::publish($change);
        if ($remote) {
            SourceChangeBus::whileApplyingRemote($publish);
        } else {
            $publish();
        }
        self::assertSame(['email' => 0, 'sms' => 0], $this->reads, 'The write may only mark dirty.');
        self::assertSame([], $this->frames());
        $this->tick();
        $frames = $this->frames();
        self::assertCount(7, $frames);
        foreach ($frames as [$acceptKey, $name, $data]) {
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $name);
            self::assertInstanceOf(PageResponseSignalData::class, $data);
            [$page, $channel] = $recipients[$acceptKey];
            self::assertSame($page, $data->pageKey);
            $summary = $data->payload->data['channelSummary'];
            self::assertSame($channel, $summary['channel']);
            self::assertSame($channel === 'email', $summary['enabled']);
            self::assertSame($channel === 'email' ? 1 : 2, $summary['missingFields']);
            self::assertSame($channel === 'email' ? 1 : 0, $summary['overriddenFieldCount']);
            self::assertSame(2, $summary['fieldCount']);
            self::assertSame(1, $summary['secretFieldCount']);
            self::assertFalse($summary['configured']);
            $expected = ['channelSummary' => $summary];
            if ($page === HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME) {
                $frame = $data->payload->data['letterFrame'];
                self::assertSame('email', $frame['channel']);
                self::assertSame('default', $frame['source']);
                self::assertStringContainsString('482913', $frame['previewHtml']);
                $expected['letterFrame'] = $frame;
            }
            self::assertSame(['page' => $page, 'payload' => ['data' => $expected]], $data->toArray());
            self::assertStringNotContainsString('private-host', $data->toJson());
        }
        self::assertSame($held, Hilos::$sr->getTableViewport('email-0', 'held-table'));
        self::assertSame(['email' => 1, 'sms' => 1], $this->reads);
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame(['email' => 1, 'sms' => 1], $this->reads);
        SourceChangeBus::publish($change);
        $this->tick();
        self::assertCount(7, $this->frames());
        self::assertSame(['email' => 2, 'sms' => 2], $this->reads, 'Memo must expire with the pass.');
    }

    public function testFrameMemoBelongsOnlyToOneGuardedPass(): void
    {
        $this->values[MailLetterFrameSettings::SETTING_KEY] = '<shared>[[:content]]</shared>';
        $this->subscribe('frame-1', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME);
        $this->subscribe('frame-2', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME);
        $this->subscribe('overview');
        $this->changed();
        $this->tick();
        $frames = $this->frames();
        self::assertCount(3, $frames);
        self::assertSame($frames[0][2]->payload->data['letterFrame'], $frames[1][2]->payload->data['letterFrame']);
        self::assertArrayNotHasKey('letterFrame', $frames[2][2]->payload->data);
        self::assertSame(1, $this->frameReads);
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame(1, $this->frameReads);
        $this->values[MailLetterFrameSettings::SETTING_KEY] = '<next>[[:content]]</next>';
        $this->changed();
        $this->tick();
        $frames = $this->frames();
        self::assertCount(3, $frames);
        self::assertSame('<next>[[:content]]</next>', $frames[0][2]->payload->data['letterFrame']['html']);
        self::assertSame(2, $this->frameReads);
    }

    public function testFrameEchoAndResetReadTheCurrentSourceAndApplyTheSameViewerMask(): void
    {
        $this->subscribe('frame', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME);
        $this->subscribe('overview');
        foreach ([false, true] as $shown) {
            $this->browser->viewer = true;
            $this->frameVisible = $shown;
            $this->values[MailLetterFrameSettings::SETTING_KEY] = '<remote>[[:content]]</remote>';
            $this->changed();
            $this->tick();
            $frames = $this->frames();
            self::assertCount(2, $frames);
            $frame = $frames[0][2]->payload->data['letterFrame'];
            self::assertSame('settings', $frame['source']);
            self::assertSame('email', $frame['channel']);
            self::assertSame($shown ? '<remote>[[:content]]</remote>' : HiddenValue::mark(), $frame['html']);
            if ($shown) {
                self::assertStringStartsWith('<remote>', $frame['previewHtml']);
                self::assertStringContainsString('482913', $frame['previewHtml']);
            } else {
                self::assertSame(HiddenValue::mark(), $frame['previewHtml']);
            }
            self::assertArrayNotHasKey('letterFrame', $frames[1][2]->payload->data);
        }
        unset($this->values[MailLetterFrameSettings::SETTING_KEY]);
        SourceChangeBus::whileApplyingRemote(fn () => $this->changed());
        $this->tick();
        $frame = $this->frames()[0][2]->payload->data['letterFrame'];
        self::assertSame('default', $frame['source']);
        self::assertStringNotContainsString('<remote>', $frame['previewHtml']);
    }

    public function testBrokenFrameOwesTheWholePageAndRecoversOnTheNextSettingsFact(): void
    {
        $this->subscribe('broken', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME);
        $this->subscribe('overview');
        $this->values[MailLetterFrameSettings::SETTING_KEY] = 'broken private frame';
        $this->changed();
        $this->tick();
        $frames = $this->frames();
        self::assertSame(['subscription_page_error', 'page_response'], array_column($frames, 1));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('broken'));
        $resender = new SummaryAudienceTestResender($this->agent);
        $resender->answer = true;
        $this->browser->bindPageResender($resender);
        $this->browser->flushToSignalRouter();
        $this->values[MailLetterFrameSettings::SETTING_KEY] = '<repaired>[[:content]]</repaired>';
        $this->changed();
        $this->tick();
        $frames = $this->frames();
        self::assertCount(2, $frames);
        self::assertSame(1, $resender->attempts);
        self::assertSame('settings', $frames[0][2]->payload->data['letterFrame']['source']);
        self::assertArrayHasKey('pageLabel', $frames[0][2]->payload->data);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('broken'));
    }

    /** @return iterable<string, array{SourceChange, bool}> Every settings operation with both provenances */
    public static function settingsFacts(): iterable
    {
        $facts = [
            'create' => SourceChange::dbCreated(HilosDbContext::settings, '1', []),
            'update' => SourceChange::dbUpdated(HilosDbContext::settings, '1', ['value' => '1']),
            'delete' => SourceChange::dbDeleted(HilosDbContext::settings, '1'),
            'clear' => SourceChange::dbCleared(HilosDbContext::settings),
        ];
        foreach ($facts as $operation => $fact) {
            foreach ([false, true] as $remote) {
                yield $operation . ($remote ? '-remote' : '-local') => [$fact, $remote];
            }
        }
    }

    public function testCommitReleasesTheReactionAndRollbackNeverPublishes(): void
    {
        $this->subscribe('ak');
        foreach ([false, true] as $commit) {
            Database::transactionStart();
            try {
                $this->changed();
                $this->tick();
                self::assertSame(['email' => 0, 'sms' => 0], $this->reads);
                self::assertSame([], $this->frames());
                if ($commit) {
                    Database::transactionCommit();
                }
            } finally {
                Database::transactionRollback();
            }
            $this->tick();
            self::assertCount($commit ? 1 : 0, $this->frames());
            self::assertSame(['email' => $commit ? 1 : 0, 'sms' => 0], $this->reads);
        }
    }

    public function testIrrelevantSourcesAndAbsentAudienceNeverReadASummary(): void
    {
        $this->subscribe('other', 'unrelated-page');
        $this->changed();
        $this->tick();
        $this->subscribe('ak');
        SourceChangeBus::publish(SourceChange::dbCleared(HilosDbContext::users));
        SourceChangeBus::publish(SourceChange::rtUpdated(HilosDbContext::settings, '1', []));
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame(['email' => 0, 'sms' => 0], $this->reads);
    }

    public function testCurrentMirrorIncludesANewSubscriberAndExcludesOneWhoLeft(): void
    {
        $this->subscribe('left');
        $this->changed();
        $this->leave('left');
        $this->subscribe('new', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION, 'sms');
        $this->tick();
        self::assertSame(['new'], array_column($this->frames(), 0));
        self::assertSame(['email' => 0, 'sms' => 1], $this->reads);
    }

    public function testOnlySubscriptionsBoundToTheCurrentServingAgentArePublished(): void
    {
        $this->subscribe('mine');
        $this->subscribe('other-type', agentType: 'another-agent');
        $this->subscribe('other-instance', agentIndex: '7');
        $this->subscribe('unbound');
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            new WebSocketPageSubscribeSignalDTO('unbound', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, ['channelId' => 'email']));
        $this->changed();
        $this->tick();
        self::assertSame(['mine'], array_column($this->frames(), 0));
        self::assertSame(['email' => 1, 'sms' => 0], $this->reads);
    }

    public function testASubscriberAddedWithoutAFactGetsItsInitialWholeAnswerOnly(): void
    {
        $this->subscribe('new', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION);
        new SummaryAudienceTestConfigurationPage($this->agent)->onSubscribe('new', new PageRouteParams(['channelId' => 'email']));
        $initial = $this->frames();
        self::assertCount(1, $initial);
        self::assertArrayHasKey('pageLabel', $initial[0][2]->payload->data);
        self::assertArrayHasKey('channelSummary', $initial[0][2]->payload->data);
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame(['email' => 1, 'sms' => 0], $this->reads);
    }

    public function testGuardRefusalPrecedesReadsAndViewerBridgeKeepsOnlyEnablementPersonal(): void
    {
        $this->subscribe('ak');
        $this->browser->requireAuthentication = true;
        $anonymous = new SummaryAudienceTestBrowser(null, false);
        $anonymous->viewer = true;
        $anonymous->requireAuthentication = true;
        SummaryAudienceTestHilos::initBrowser($anonymous);
        $this->changed();
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame(['email' => 0, 'sms' => 0], $this->reads);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));

        SummaryAudienceTestHilos::initBrowser($this->browser);
        $this->browser->viewer = true;
        $this->subscribe('sms', channel: 'sms');
        $this->changed();
        $this->tick();
        $frames = $this->frames();
        self::assertCount(2, $frames);
        foreach ($frames as [$acceptKey, $name, $data]) {
            $summary = $data->payload->data['channelSummary'];
            self::assertSame($acceptKey === 'ak' ? HiddenValue::mark() : false, $summary['enabled']);
            self::assertSame(ucfirst($summary['channel']), $summary['label']);
            self::assertSame('transport', $summary['driver']);
            self::assertSame(2, $summary['missingFields']);
            self::assertSame(2, $summary['fieldCount']);
            self::assertSame(1, $summary['secretFieldCount']);
            self::assertSame(0, $summary['overriddenFieldCount']);
            self::assertFalse($summary['configured']);
        }
    }

    public function testOneBrokenChannelIsContainedAndMemoizedWhileAnotherStillPublishes(): void
    {
        $this->subscribe('broken-1');
        $this->subscribe('good', channel: 'sms');
        $this->subscribe('broken-2', HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES);
        $this->onRead['email'] = static function (): void { throw new RuntimeException('private failure'); };
        $this->changed();
        $this->tick();
        $frames = $this->frames();
        self::assertSame(['broken-1', 'good', 'broken-2'], array_column($frames, 0));
        self::assertSame(['subscription_page_error', SignalTypeConstants::PAGE_RESPONSE, 'subscription_page_error'], array_column($frames, 1));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('broken-1'));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('broken-2'));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('good'));
        self::assertSame(['email' => 1, 'sms' => 1], $this->reads);
    }

    public function testDebtUsesTheWholeResenderBeforeReadingAndAPartialCannotPayIt(): void
    {
        $this->subscribe('ak', HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION);
        $resender = new SummaryAudienceTestResender($this->agent);
        $this->browser->bindPageResender($resender);
        Hilos::$sr->markPageDeliveryFailure('ak');
        $this->changed();
        $this->tick();
        self::assertSame(1, $resender->attempts);
        self::assertSame(['email' => 0, 'sms' => 0], $this->reads);
        self::assertSame([], $this->frames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak'));
        $this->browser->flushToSignalRouter();
        $resender->answer = true;
        $this->changed();
        $this->tick();
        self::assertSame(2, $resender->attempts);
        $frames = $this->frames();
        self::assertCount(1, $frames);
        self::assertArrayHasKey('pageLabel', $frames[0][2]->payload->data);
        self::assertArrayHasKey('channelSummary', $frames[0][2]->payload->data);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));
        self::assertSame(['email' => 1, 'sms' => 0], $this->reads, 'Only the complete page read the summary.');
    }

    /** @param bool $leave Remove the subscription instead of changing its params */
    #[DataProvider('departures')]
    public function testReplacementBeforeCallbackNeverSendsTheOldChannelsSummary(bool $leave): void
    {
        $this->subscribe('ak');
        $this->browser->beforePublication = function () use ($leave): void {
            $leave ? $this->leave('ak') : $this->update('ak', 'sms');
        };
        $this->changed();
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertSame(['email' => 0, 'sms' => 0], $this->reads);
    }

    /** @param bool $leave Remove the subscription instead of changing its params */
    #[DataProvider('departures')]
    public function testReplacementWhileReadingDiscardsTheBuiltSummary(bool $leave): void
    {
        $this->subscribe('ak');
        $this->onRead['email'] = function () use ($leave): void {
            $leave ? $this->leave('ak') : $this->update('ak', 'sms');
        };
        $this->changed();
        $this->tick();
        self::assertSame([], $this->frames());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('ak'));
        self::assertSame(['email' => 1, 'sms' => 0], $this->reads);
    }

    public function testSnapshotRecordReplacedByAnEarlierRecipientIsSkippedBeforePublication(): void
    {
        $this->subscribe('first', channel: 'sms');
        $this->subscribe('later');
        $this->onRead['sms'] = fn () => $this->update('later', 'sms');
        $this->changed();
        $this->tick();
        self::assertSame(['first'], array_column($this->frames(), 0));
        self::assertSame(['first'], $this->browser->publications);
        self::assertSame(['email' => 0, 'sms' => 1], $this->reads);
    }

    public function testAgentTickPublishesAndStopResetsTheDeferredFact(): void
    {
        $this->subscribe('ak');
        $this->changed();
        $this->agent->onTick();
        self::assertCount(1, $this->frames());
        $this->changed();
        $this->agent->onStop();
        $this->agent->onTick();
        self::assertSame([], $this->frames());
        self::assertSame(['email' => 1, 'sms' => 0], $this->reads);
    }

    /** @return iterable<string, array{bool}> Both immutable subscription departures */
    public static function departures(): iterable
    {
        yield 'update' => [false];
        yield 'unsubscribe' => [true];
    }

    /**
     * @param string $channel Registry channel whose applicable tabs are served
     * @return list<string> All applicable channel context page keys
     */
    private function tabs(string $channel): array
    {
        return [HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
            HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION, HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES,
            ...($channel === 'email' ? [HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME] : [])];
    }

    /**
     * @param string $acceptKey Subscriber
     * @param string $page Channel page
     * @param string $channel Channel address
     * @param string $agentType Serving agent type
     * @param ?string $agentIndex Serving agent instance
     */
    private function subscribe(
        string $acceptKey,
        string $page = HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL,
        string $channel = 'email',
        string $agentType = HilosAgentType::HILOS_INDEX,
        ?string $agentIndex = null,
    ): void {
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($acceptKey, $page, ['channelId' => $channel]));
        Hilos::$sr->bindPageAgent($acceptKey, $page, $agentType, $agentIndex);
    }

    /** @param string $acceptKey Subscriber leaving */
    private function leave(string $acceptKey): void
    {
        $page = Hilos::$sr->pageSubscription($acceptKey)->page;
        Hilos::$sr->unsubscribeFromPage($page, new WebSocketPageUnsubscribeSignalDTO($acceptKey, $page));
    }

    /**
     * @param string $acceptKey Subscriber whose params move
     * @param string $channel New channel address
     */
    private function update(string $acceptKey, string $channel): void
    {
        $page = Hilos::$sr->pageSubscription($acceptKey)->page;
        Hilos::$sr->updatePageSubscription($page, new WebSocketPageUpdateSubscriptionSignalDTO($acceptKey, $page, ['channelId' => $channel]));
    }

    private function changed(): void
    {
        SourceChangeBus::publish(SourceChange::dbCleared(HilosDbContext::settings));
    }

    private function tick(): void
    {
        CommunicationsChannelSummaryAudience::onAgentTick($this->agent);
    }

    /** @return list<array{string, string, object}> Addressed frames from the real router queue */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $frames[] = [$signal->data->targetAcceptKey, $signal->signalName->getName(), $signal->data->data];
        }
        return $frames;
    }
}

final class SummaryAudienceTestAgent extends AbstractHilosIndexAgent
{
}

final class SummaryAudienceTestChannelPage extends AbstractHilosCommunicationsChannelPage
{
}

final class SummaryAudienceTestConfigurationPage extends AbstractHilosCommunicationsChannelConfigurationPage
{
}

final class SummaryAudienceTestDeliveriesPage extends AbstractHilosCommunicationsDeliveriesPage
{
}

abstract class SummaryAudienceTestHilos extends Hilos
{
    public const array PAGES = [
        SummaryAudienceTestChannelPage::PAGE => SummaryAudienceTestChannelPage::class,
        SummaryAudienceTestConfigurationPage::PAGE => SummaryAudienceTestConfigurationPage::class,
        SummaryAudienceTestDeliveriesPage::PAGE => SummaryAudienceTestDeliveriesPage::class,
        SummaryAudienceTestFramePage::PAGE => SummaryAudienceTestFramePage::class,
    ];

    protected const string NOTIFICATION_CHANNEL_REGISTRY = SummaryAudienceTestRegistry::class;
}

final class SummaryAudienceTestRegistry extends DeliveryChannelRegistry
{
    /** @var array<string, AbstractDeliveryChannel> Test's instrumented channel descriptors */
    public static array $channels = [];

    /** @return array<string, AbstractDeliveryChannel> Current fixture descriptors */
    protected static function channels(): array
    {
        return self::$channels;
    }
}

final class SummaryAudienceTestBrowser extends IdentityTestBrowser
{
    public bool $viewer = false;
    public bool $requireAuthentication = false;
    public ?Closure $beforePublication = null;

    /** @var list<string> Recipients that reached the publication boundary */
    public array $publications = [];

    /**
     * @param string $page Subscribed page
     * @param string $acceptKey Recipient
     * @param Closure(): PagePayload $build Real publisher callback
     * @param array<string, WireField> $fields Real publisher projection
     */
    public function publishPageData(string $page, string $acceptKey, Closure $build, array $fields): void
    {
        $this->publications[] = $acceptKey;
        if ($this->beforePublication !== null) {
            $before = $this->beforePublication;
            $this->beforePublication = null;
            $before();
        }
        parent::publishPageData($page, $acceptKey, $build, $fields);
    }

    /**
     * @param string $pageClass Subscribed class
     * @param string $acceptKey Recipient
     * @return bool Fixture viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /**
     * @param string $page Subscribed page
     * @return ?BrowserPageConfig Real guard declaration selected by the test
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return BrowserPageConfig::fromArray([
            BrowserConfigKey::GUARDS => $this->requireAuthentication ? [[BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED]] : [],
        ]);
    }
}

final class SummaryAudienceTestResender implements PageResender
{
    public int $attempts = 0;
    public bool $answer = false;

    /** @param SummaryAudienceTestAgent $agent Agent used by the complete subscription answer */
    public function __construct(private readonly SummaryAudienceTestAgent $agent)
    {
    }

    /**
     * @param string $page Page owed whole
     * @param string $acceptKey Recipient
     * @param array<string, mixed> $params Current immutable record's params
     * @return PageResendOutcome A complete real page answer or a failed probe
     */
    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        $this->attempts++;
        if (!$this->answer) {
            return PageResendOutcome::Failed;
        }
        $class = $page === HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL_FRAME
            ? SummaryAudienceTestFramePage::class : SummaryAudienceTestConfigurationPage::class;
        new $class($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        return PageResendOutcome::Answered;
    }

    /**
     * @param string $page Unused deleted resource page
     * @param string $acceptKey Unused recipient
     * @return PageResendOutcome No deleted resource service in this fixture
     */
    public function refuseDeletedResource(string $page, string $acceptKey): PageResendOutcome
    {
        return PageResendOutcome::Unserved;
    }
}

final class SummaryAudienceTestFramePage extends AbstractHilosCommunicationsChannelFramePage
{
}
