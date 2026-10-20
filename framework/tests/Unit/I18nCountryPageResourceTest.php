<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ArrayAccess;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\LogicException;
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
use Hilos\Hilos;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryCodePage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryNamesPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryPage;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguagePage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A country page names its row by primary id (HIL-1600).
 *
 * The address stays the country code. Overview and Names share one declaration.
 * An unknown code refuses before the frame. A language page names no row.
 */
final class I18nCountryPageResourceTest extends TestCase
{
    private const string ACCEPT_KEY = 'i18n-country-page';

    private const string COUNTRY_CODE = 'pl';

    private const int COUNTRY_ID = 141;

    private const string UNKNOWN_CODE = 'zz';

    private const string LANGUAGE_CODE = 'en';

    private const int LANGUAGE_ID = 3;

    private ?HilosDbContext $previousDb = null;

    private ?SignalRouter $previousRouter = null;

    private ?BrowserContext $previousBrowser = null;

    private I18nCountryPageResourceTestRows $countries;

    private I18nCountryPageResourceTestRows $languages;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->countries = new I18nCountryPageResourceTestRows([self::COUNTRY_CODE => self::COUNTRY_ID]);
        $this->languages = new I18nCountryPageResourceTestRows([self::LANGUAGE_CODE => self::LANGUAGE_ID]);
        Hilos::$db = new I18nCountryPageResourceTestDb($this->countries, $this->languages);
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousRouter;
        Hilos::$browser = $this->previousBrowser;

        parent::tearDown();
    }

    public function testKnownCountryBindsThePrimaryIdAfterPageResponse(): void
    {
        $page = new I18nCountryPageResourceTestOverviewPage(new I18nCountryPageResourceTestAgent());
        self::assertSame(
            AbstractHilosI18nCountryCodePage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );
        self::assertSame(
            AbstractPage::class,
            (new ReflectionMethod($page, 'onSubscribeBeforeResponse'))->getDeclaringClass()->getName(),
        );

        $page->onSubscribe(self::ACCEPT_KEY, $this->countryAddress(self::COUNTRY_CODE));

        $this->assertLookedUpCode($this->countries, self::COUNTRY_CODE);
        $this->assertPageResponse(AbstractHilosI18nCountryPage::PAGE);
        $this->assertBoundCountry();
    }

    public function testCountryNamesPageBindsTheSamePrimaryIdWithoutItsOwnOverride(): void
    {
        $page = new I18nCountryPageResourceTestNamesPage(new I18nCountryPageResourceTestAgent());
        self::assertSame(
            AbstractHilosI18nCountryCodePage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );
        self::assertNotSame(
            AbstractHilosI18nCountryNamesPage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );

        $page->onSubscribe(self::ACCEPT_KEY, $this->countryAddress(self::COUNTRY_CODE));

        $this->assertLookedUpCode($this->countries, self::COUNTRY_CODE);
        $this->assertPageResponse(AbstractHilosI18nCountryNamesPage::PAGE);
        $this->assertBoundCountry();
    }

    public function testUnknownCountryThrowsBeforeTheFrameAndBindsNothing(): void
    {
        $page = new I18nCountryPageResourceTestOverviewPage(new I18nCountryPageResourceTestAgent());

        try {
            $page->onSubscribe(self::ACCEPT_KEY, $this->countryAddress(self::UNKNOWN_CODE));
            self::fail('Expected an unknown country to refuse the subscription');
        } catch (PageResourceNotFoundException $e) {
            self::assertSame('Unknown country: ' . self::UNKNOWN_CODE, $e->getMessage());
            self::assertSame(404, $e->httpCode);
            self::assertSame('not_found', $e->errorCode);
        }

        self::assertSame([self::UNKNOWN_CODE], $this->countries->lookups);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertNull(Hilos::$sr?->pageResource(self::ACCEPT_KEY));
    }

    public function testLanguagePageDoesNotDeclareAPageResource(): void
    {
        $page = new I18nCountryPageResourceTestLanguagePage(new I18nCountryPageResourceTestAgent());
        self::assertSame(
            AbstractPage::class,
            (new ReflectionMethod($page, 'resolvePageResource'))->getDeclaringClass()->getName(),
        );

        $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams(['languageCode' => self::LANGUAGE_CODE]));

        self::assertSame([self::LANGUAGE_CODE], $this->languages->lookups);
        self::assertSame([], $this->countries->lookups);
        $this->assertPageResponse(AbstractHilosI18nLanguagePage::PAGE);
        self::assertNull(Hilos::$sr?->pageResource(self::ACCEPT_KEY));
    }

    public function testUpdateSubscriptionRebindsTheSameCountry(): void
    {
        $page = new I18nCountryPageResourceTestOverviewPage(new I18nCountryPageResourceTestAgent());
        $address = $this->countryAddress(self::COUNTRY_CODE);

        $page->onSubscribe(self::ACCEPT_KEY, $address);
        $this->assertPageResponse(AbstractHilosI18nCountryPage::PAGE);
        $lookupsAfterSubscribe = count($this->countries->lookups);

        $page->onUpdateSubscription(self::ACCEPT_KEY, $address);

        self::assertSame($lookupsAfterSubscribe * 2, count($this->countries->lookups));
        $this->assertLookedUpCode($this->countries, self::COUNTRY_CODE);
        $this->assertPageResponse(AbstractHilosI18nCountryPage::PAGE);
        $this->assertBoundCountry();
    }

    /**
     * @param string $countryCode Country code to address
     * @return PageRouteParams Route parameters
     */
    private function countryAddress(string $countryCode): PageRouteParams
    {
        return new PageRouteParams(['countryCode' => $countryCode]);
    }

    /**
     * @param I18nCountryPageResourceTestRows $rows Collection the page read
     * @param string $code Code every lookup must have been
     */
    private function assertLookedUpCode(I18nCountryPageResourceTestRows $rows, string $code): void
    {
        self::assertNotSame([], $rows->lookups);
        foreach ($rows->lookups as $lookup) {
            self::assertSame($code, $lookup);
        }
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

    private function assertBoundCountry(): void
    {
        $resource = Hilos::$sr?->pageResource(self::ACCEPT_KEY);
        self::assertInstanceOf(PageResource::class, $resource);
        self::assertSame(HilosDbContext::countries, $resource->collectionKey);
        self::assertSame((string)self::COUNTRY_ID, $resource->idString);
        self::assertNotSame(self::COUNTRY_CODE, $resource->idString);
    }
}

/** Countries and languages for the page, with no database behind them. */
final class I18nCountryPageResourceTestDb extends HilosDbContext
{
    /**
     * @param I18nCountryPageResourceTestRows $countries Country rows by code
     * @param I18nCountryPageResourceTestRows $languages Language rows by code
     */
    public function __construct(
        private readonly I18nCountryPageResourceTestRows $countries,
        private readonly I18nCountryPageResourceTestRows $languages,
    ) {
    }

    /**
     * @param string $name Collection name
     * @return I18nCountryPageResourceTestRows Rows of that collection
     * @throws LogicException When the page reads a collection this double does not carry
     */
    public function __get(string $name)
    {
        return match ($name) {
            self::countries => $this->countries,
            self::languages => $this->languages,
            default => throw new LogicException('Unexpected collection: ' . $name),
        };
    }
}

/**
 * Code-keyed rows.
 *
 * offsetExists asks offsetGet, the way the countries collection does, so a
 * missing code and a present one both pass through the same read.
 */
final class I18nCountryPageResourceTestRows implements ArrayAccess
{
    /** @var list<string> Offsets read, in order */
    public array $lookups = [];

    /**
     * @param array<string, int> $ids Primary id by code
     */
    public function __construct(private readonly array $ids)
    {
    }

    /**
     * @param mixed $offset Code the page asked for
     * @return bool Whether that code is present
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * @param mixed $offset Code the page asked for
     * @return ?I18nCountryPageResourceTestRow Row, or null when the code is unknown
     */
    public function offsetGet(mixed $offset): mixed
    {
        $this->lookups[] = is_string($offset) ? $offset : 'not-a-string';
        if (!is_string($offset) || !isset($this->ids[$offset])) {
            return null;
        }

        return new I18nCountryPageResourceTestRow($this->ids[$offset]);
    }

    /**
     * @param mixed $offset Unused
     * @param mixed $value Unused
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
    }

    /**
     * @param mixed $offset Unused
     */
    public function offsetUnset(mixed $offset): void
    {
    }
}

/** A row whose primary id is not its code. */
final class I18nCountryPageResourceTestRow
{
    /**
     * @param int $id Primary id
     */
    public function __construct(public readonly int $id)
    {
    }
}

/** Test agent supplying the page signal source. */
final class I18nCountryPageResourceTestAgent implements PageAgentInterface
{
    /**
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'i18n-country-page-agent';
    }

    /**
     * @return SignalSourceInterface Agent signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, $this->getId());
    }
}

final class I18nCountryPageResourceTestOverviewPage extends AbstractHilosI18nCountryPage
{
}

final class I18nCountryPageResourceTestNamesPage extends AbstractHilosI18nCountryNamesPage
{
}

final class I18nCountryPageResourceTestLanguagePage extends AbstractHilosI18nLanguagePage
{
}
