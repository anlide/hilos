<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Communications;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelFramePage;
use Hilos\Pages\Communications\AbstractHilosCommunicationsChannelPage;
use Hilos\Pages\Communications\CommunicationsLetterFrameReader;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The initial answer uses the real reader, identity assembly and viewer bridge. */
final class CommunicationsChannelFramePageTest extends TestCase
{
    private ?HilosDbContext $previousDb;
    private ?SettingsAccessor $previousSetting;
    private ?EnvAccessor $previousEnv;
    private ?BrowserContext $previousBrowser;
    private ?SignalRouter $previousRouter;
    private string $previousApp;
    private FramePageFixture $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousEnv = Hilos::$env;
        $this->previousBrowser = Hilos::$browser;
        $this->previousRouter = Hilos::$sr;
        $this->previousApp = Hilos::appClass();
        Hilos::$db = null;
        Hilos::$env = null;
        Hilos::$setting = new SettingsAccessor(ChannelSettingsCatalog::class);
        Hilos::$sr = new FramePageRouter();
        FramePageApp::initBrowser(new FramePageBrowser());
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'frame-page'));
        $this->page = new FramePageFixture($agent);
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        Hilos::$env = $this->previousEnv;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$sr = $this->previousRouter;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    public function testInitialAndUpdatedAnswerContainTheWholeFrameSummaryAndOwnerIdentityExactlyOnce(): void
    {
        foreach (['onSubscribe', 'onUpdateSubscription'] as $method) {
            $this->page->$method('reader', new PageRouteParams(['channelId' => 'email']));
            $answer = $this->answer();
            self::assertSame('hilos_communications_channel_frame', $answer->pageKey);
            self::assertSame(new CommunicationsLetterFrameReader()->read()->toArray(), $answer->payload->data['letterFrame']);
            self::assertSame('Email', $answer->payload->data['channelSummary']['label']);
            self::assertSame('Letter frame', $answer->payload->data['pageLabel']);
            self::assertSame('hilos_communications_channel', $answer->payload->data['pageHeadOwner']['page']);
            self::assertSame([
                ['page' => 'hilos_communications_channel', 'label' => 'Overview'],
                ['page' => 'hilos_communications_channel_frame', 'label' => 'Letter frame'],
            ], $answer->payload->data['pageTabs']);
            self::assertSame([], $answer->payload->windows);
            self::assertSame([], $answer->payload->entities);
            self::assertSame([], $answer->payload->lists);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    public function testOnlyTheEmailOwnersRouteMakesTheTabApplicable(): void
    {
        foreach ([[], ['channelId' => 'sms'], ['channelId' => 'push'], ['channelId' => ''], ['channelId' => 3]] as $params) {
            self::assertFalse(FramePageFixture::isTabApplicable(new PageRouteParams($params)));
        }
        self::assertTrue(FramePageFixture::isTabApplicable(new PageRouteParams(['channelId' => 'email', 'other' => 'sms'])));
    }

    public function testDirectSmsAndUnknownAddressesRefuseWith404AndMalformedAddressesKeep400(): void
    {
        foreach (['onSubscribe', 'onUpdateSubscription'] as $method) {
            foreach (['sms', 'missing'] as $channel) {
                try {
                    $this->page->$method('reader', new PageRouteParams(['channelId' => $channel]));
                    self::fail('A non-email address must not render a frame.');
                } catch (PageResourceNotFoundException $failure) {
                    self::assertSame(404, $failure->httpCode);
                }
            }
            foreach ([[], ['channelId' => ''], ['channelId' => false]] as $params) {
                try {
                    $this->page->$method('reader', new PageRouteParams($params));
                    self::fail('The existing malformed-address refusal must remain.');
                } catch (MissingPageRouteParamException $failure) {
                    self::assertSame(400, $failure->httpCode);
                }
            }
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    public function testViewerKeepsChannelAndSourceWhileBothHtmlFieldsFollowTheSettingVerdict(): void
    {
        Hilos::$browser->viewer = true;
        foreach ([false, true] as $shown) {
            Hilos::$setting = new FramePageSettings($shown);
            $this->page->onSubscribe('reader', new PageRouteParams(['channelId' => 'email']));
            $frame = $this->answer()->payload->data['letterFrame'];
            self::assertSame('email', $frame['channel']);
            self::assertSame('project', $frame['source']);
            $expected = new CommunicationsLetterFrameReader()->read()->toArray();
            self::assertSame($shown ? $expected['html'] : HiddenValue::mark(), $frame['html']);
            self::assertSame($shown ? $expected['previewHtml'] : HiddenValue::mark(), $frame['previewHtml']);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        }
    }

    private function answer(): PageResponseSignalData
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame('reader', $signal->data->targetAcceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        return $signal->data->data;
    }
}

final class FramePageFixture extends AbstractHilosCommunicationsChannelFramePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'frame-agent';
}

abstract class FramePageApp extends Hilos
{
    public const array PAGES = [
        FramePageFixture::PAGE => FramePageFixture::class,
        FramePageOverviewFixture::PAGE => FramePageOverviewFixture::class,
    ];
    protected const string NOTIFICATION_CHANNEL_REGISTRY = FramePageRegistry::class;
    protected const string PAGE_CATALOG = FramePageCatalog::class;
}

final class FramePageRegistry extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return ['email' => new FramePageChannel(), 'sms' => new SmsDeliveryChannel()];
    }
}

final class FramePageChannel extends MailDeliveryChannel
{
    public const ?string LETTER_FRAME = '<project>[[:content]]</project>';
}

final class FramePageCatalog implements PageCatalogProviderInterface
{
    public static function dashboardSections(): array
    {
        return [];
    }

    public static function pages(): array
    {
        return [HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL => array_replace(
            HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL],
            [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
        )];
    }
}

final class FramePageRouter extends SignalRouter
{
    protected function hilosClass(): string
    {
        return FramePageApp::class;
    }
}

final class FramePageBrowser extends BrowserContext
{
    public bool $viewer = false;
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

final class FramePageSettings extends SettingsAccessor
{
    public function __construct(private readonly bool $shown)
    {
        parent::__construct(ChannelSettingsCatalog::class);
    }
    public function visibleInAdminViewMode(string $key): bool
    {
        return $this->shown;
    }
}

final class FramePageOverviewFixture extends AbstractHilosCommunicationsChannelPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'frame-agent';
}
