<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Users;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Pages\Users\AbstractHilosUserPage;
use Hilos\Pages\Users\AbstractHilosUserSessionsPage;
use Hilos\Users\AccountErasureProgressResolver;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The person card names the person's row by primary id (HIL-1957).
 *
 * Deleting that row gives every window open on the card the live 404. Overview and Sessions share
 * one declaration. An unknown person refuses before the frame.
 */
final class HilosUserPageResourceTest extends TestCase
{
    private const string ACCEPT_KEY = 'hilos-user-page';

    private const int USER_ID = 12;

    private const int UNKNOWN_ID = 999999;

    private ?HilosDbContext $previousDb = null;

    private ?SignalRouter $previousRouter = null;

    private ?BrowserContext $previousBrowser = null;

    private ?SettingsAccessor $previousSettings = null;

    private HilosUserPageTestDb $db;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousSettings = Hilos::$setting;
        $this->db = new HilosUserPageTestDb([self::USER_ID]);
        Hilos::$db = $this->db;
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = null;
        Hilos::$setting = null;
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
    }

    protected function tearDown(): void
    {
        AccountStandingResolver::forgetAll();
        AccountErasureProgressResolver::forgetAll();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;
        Hilos::$setting = $this->previousSettings;

        parent::tearDown();
    }

    public function testKnownPersonBindsThePrimaryIdAfterPageResponse(): void
    {
        $page = new HilosUserPageResourceTestOverviewPage(new HilosUserPageResourceTestAgent());
        self::assertSame(
            AbstractHilosUserPage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );

        $page->onSubscribe(self::ACCEPT_KEY, $this->personAddress(self::USER_ID));

        self::assertContains(self::USER_ID, $this->db->users->lookups);
        $this->assertPageResponse(AbstractHilosUserPage::PAGE);
        $this->assertBoundPerson();
    }

    public function testSessionsTabBindsTheSamePrimaryIdWithoutItsOwnOverride(): void
    {
        $page = new HilosUserPageResourceTestSessionsPage(new HilosUserPageResourceTestAgent());
        self::assertSame(
            AbstractHilosUserPage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );
        self::assertNotSame(
            AbstractHilosUserSessionsPage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );

        $page->onSubscribe(self::ACCEPT_KEY, $this->personAddress(self::USER_ID));

        self::assertContains(self::USER_ID, $this->db->users->lookups);
        $this->assertPageResponse(AbstractHilosUserSessionsPage::PAGE);
        $this->assertBoundPerson();
    }

    public function testUnknownPersonThrowsBeforeTheFrameAndBindsNothing(): void
    {
        $page = new HilosUserPageResourceTestOverviewPage(new HilosUserPageResourceTestAgent());

        try {
            $page->onSubscribe(self::ACCEPT_KEY, $this->personAddress(self::UNKNOWN_ID));
            self::fail('Expected an unknown person to refuse the subscription');
        } catch (PageResourceNotFoundException $e) {
            self::assertSame('No such user: ' . self::UNKNOWN_ID, $e->getMessage());
            self::assertSame(404, $e->httpCode);
            self::assertSame('not_found', $e->errorCode);
        }

        self::assertSame([self::UNKNOWN_ID], $this->db->users->lookups);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertNull(Hilos::$sr?->pageResource(self::ACCEPT_KEY));
    }

    /**
     * @param int $userId Person id to address
     * @return PageRouteParams Route parameters
     */
    private function personAddress(int $userId): PageRouteParams
    {
        return new PageRouteParams(['userId' => (string)$userId]);
    }

    /**
     * @param string $page Page key the frame must name
     */
    private function assertPageResponse(string $page): void
    {
        $queued = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($queued);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $queued->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $queued->data);
        self::assertInstanceOf(PageResponseSignalData::class, $queued->data->data);
        self::assertSame($page, $queued->data->data->pageKey);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    private function assertBoundPerson(): void
    {
        $resource = Hilos::$sr?->pageResource(self::ACCEPT_KEY);
        self::assertInstanceOf(PageResource::class, $resource);
        self::assertSame(HilosDbContext::users, $resource->collectionKey);
        self::assertSame((string)self::USER_ID, $resource->idString);
    }
}

/** Test agent supplying the page signal source. */
final class HilosUserPageResourceTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'hilos-user-page-agent';
    }

    /**
     * @return SignalSourceInterface Agent signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

final class HilosUserPageResourceTestOverviewPage extends AbstractHilosUserPage
{
}

final class HilosUserPageResourceTestSessionsPage extends AbstractHilosUserSessionsPage
{
}
