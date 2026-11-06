<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Core\TruthSource\TruthSourceRegistry;

/** The demo's real i18n pages admit primary ids before sending their single page response. */
final class I18nPageAddressTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'i18n-address-viewer';
    private I18nLibraryAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->clearFixtures();

        $this->agent = new I18nLibraryAgent();
        TruthSourceRegistry::register(HilosDbContext::languages, TruthSourceKeys::all(), $this->agent->getId());
        TruthSourceRegistry::register(HilosDbContext::countries, TruthSourceKeys::all(), $this->agent->getId());
        $this->underAgent($this->agent, static function (): void {
            $language = Hilos::$db->languages->actions->create('qx', 'Test language', false);
            $language->actions->switchOn();
            Hilos::$db->languages->actions->create('qy', 'Other language', false);
            $country = Hilos::$db->countries->actions->create('qx', '$', 'USD');
            $country->actions->switchOn();
            Hilos::$db->countries->actions->create('qy', '€', 'EUR');
        });
    }

    protected function tearDown(): void
    {
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testEnabledAndDisabledIdsGiveOneResponseForEachPage(): void
    {
        foreach ([
            [LanguageDetailPage::class, 'languageId'],
            [LanguageNamesPage::class, 'languageId'],
            [LanguageLocalesPage::class, 'languageId'],
            [CountryDetailPage::class, 'countryId'],
            [CountryNamesPage::class, 'countryId'],
        ] as [$pageClass, $key]) {
            foreach (['qx', 'qy'] as $code) {
                $collection = $key === 'countryId' ? Hilos::$db->countries : Hilos::$db->languages;
                $responses = $this->subscribe($pageClass, [$key => (string)$collection[$code]->id]);
                self::assertCount(1, $responses);
                self::assertSame($pageClass::PAGE, $responses[0]->pageKey);
            }
        }
    }

    public function testBothAddressesAreRevalidatedOnUpdate(): void
    {
        foreach ([
            [LanguageDetailPage::class, 'languageId'],
            [CountryDetailPage::class, 'countryId'],
            [CountryNamesPage::class, 'countryId'],
            [LanguageNamesPage::class, 'languageId'],
            [LanguageLocalesPage::class, 'languageId'],
        ] as [$pageClass, $key]) {
            $collection = $key === 'countryId' ? Hilos::$db->countries : Hilos::$db->languages;
            $this->assertRefusal($pageClass, [], MissingPageRouteParamException::class);
            $this->assertRefusal($pageClass, [$key => '+1'], InvalidPageRouteParamException::class);
            $this->assertRefusal($pageClass, [$key => '999999'], PageResourceNotFoundException::class);

            foreach ([false, true] as $update) {
                foreach (['qx', '0', '-1', '+1', '01', '1.0', ' 1', "1\n", (string)PHP_INT_MAX . '0'] as $invalid) {
                    $this->assertRefusal($pageClass, [$key => $invalid], InvalidPageRouteParamException::class, $update);
                }
                $oldKey = $key === 'countryId' ? 'countryCode' : 'languageCode';
                $this->assertRefusal($pageClass, [$oldKey => 'qx'], MissingPageRouteParamException::class, $update);
            }

            self::assertCount(1, $this->subscribe($pageClass, [$key => (string)$collection['qx']->id]));
            self::assertCount(1, $this->subscribe($pageClass, [$key => (string)$collection['qy']->id], update: true));
            $this->assertRefusal($pageClass, [], MissingPageRouteParamException::class, update: true);
            $this->assertRefusal($pageClass, [$key => '+1'], InvalidPageRouteParamException::class, update: true);
            $this->assertRefusal($pageClass, [$key => '999999'], PageResourceNotFoundException::class, update: true);
        }
    }

    /**
     * @param class-string<AbstractPage> $pageClass Page being subscribed to
     * @param array<string, string> $params Route parameters
     * @param class-string<PageSubscriptionException> $expected Expected refusal
     * @param bool $update Whether this is a subscription update
     */
    private function assertRefusal(string $pageClass, array $params, string $expected, bool $update = false): void
    {
        try {
            $this->subscribe($pageClass, $params, $update);
            self::fail('Expected an address refusal');
        } catch (PageSubscriptionException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($expected === PageResourceNotFoundException::class ? 404 : 400, $e->httpCode);
            self::assertSame([], $this->drainResponses());
        }
    }

    /**
     * @param class-string<AbstractPage> $pageClass Page being subscribed to
     * @param array<string, string> $params Route parameters
     * @param bool $update Whether this is a subscription update
     * @return list<PageResponseSignalData> Complete page responses
     */
    private function subscribe(string $pageClass, array $params, bool $update = false): array
    {
        $this->drainResponses();
        if (!$update) {
            Hilos::$sr->subscribeToPage(
                $pageClass::PAGE,
                new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $pageClass::PAGE, $params),
            );
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), function () use ($pageClass, $params, $update): void {
            $page = new $pageClass($this->agent);
            if ($update) {
                $page->onUpdateSubscription(self::ACCEPT_KEY, new PageRouteParams($params));
            } else {
                $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
            }
        });

        return $this->drainResponses();
    }

    /** @return list<PageResponseSignalData> Responses queued since the last call */
    private function drainResponses(): array
    {
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $responses[] = $signal->data->data;
        }

        return $responses;
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country WHERE code IN ('qx', 'qy')");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('qx', 'qy')");
        Hilos::$db->reHydrateCollection(HilosDbContext::countries);
        Hilos::$db->reHydrateCollection(HilosDbContext::languages);
    }
}
