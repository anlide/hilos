<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryAddActionDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The countries list adds a switched-off row through the library's owned write door. */
final class I18nCountryAddTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;
    private bool $createdUnitedStates = false;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach ([
            HilosDbContext::languages, HilosDbContext::countries, HilosDbContext::locales,
            HilosDbContext::languageNames, HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
        $this->underAgent($this->agent, function (): void {
            if (!isset(Hilos::$db->countries['us'])) {
                Hilos::$db->countries->actions->create('us', '$', 'USD');
                $this->createdUnitedStates = true;
            }
        });
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testAddsCustomCountrySwitchedOffWithoutDefaultLocale(): void
    {
        $page = new CountriesListPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
                new HilosI18nCountryAddActionDTO('gg', '£', 'GBP')));
        });

        self::assertArrayHasKey('gg', Hilos::$db->countries);
        $country = Hilos::$db->countries['gg'];
        self::assertFalse($country->enabled);
        self::assertSame('£', $country->currencySymbol);
        self::assertSame('GBP', $country->currencyCode);
        self::assertNull($country->defaultLocaleId);
        self::assertNull(BuiltInI18nCatalog::country('gg'));
    }

    public function testDuplicateCustomCountryIsRefusedBeforeCurrencyValidation(): void
    {
        $page = new CountriesListPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            $page->onAction('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
                new HilosI18nCountryAddActionDTO('gg', '£', 'GBP'));
        });

        try {
            $this->underAgent($this->agent, static function () use ($page): void {
                $page->onAction('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
                    new HilosI18nCountryAddActionDTO('gg', '', 'GB'));
            });
            self::fail('A country code may be added only once');
        } catch (ValidationException $error) {
            self::assertSame("Country 'gg' already exists.", $error->getMessage());
        }

        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_country` WHERE `code` = ?', ['gg']);
        self::assertSame(1, (int) Database::field('count'));
    }

    public function testKnownCountryIsRefusedWithoutChangingItsCurrency(): void
    {
        self::assertArrayHasKey('us', Hilos::$db->countries);
        $before = Hilos::$db->countries['us'];
        $symbol = $before->currencySymbol;
        $code = $before->currencyCode;
        $page = new CountriesListPage($this->agent);

        try {
            $this->underAgent($this->agent, static function () use ($page): void {
                $page->onAction('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
                    new HilosI18nCountryAddActionDTO('us', '', 'GB'));
            });
            self::fail('The catalog country already exists');
        } catch (ValidationException $error) {
            self::assertSame("Country 'us' already exists.", $error->getMessage());
        }

        self::assertSame($symbol, Hilos::$db->countries['us']->currencySymbol);
        self::assertSame($code, Hilos::$db->countries['us']->currencyCode);
    }

    public function testInvalidCurrencyDoesNotCreateCountry(): void
    {
        $page = new CountriesListPage($this->agent);
        foreach ([
            ['', 'GBP', 'Country currency symbol must have 1..8 characters'],
            ['£', 'GB', 'Country currency code must have three uppercase letters'],
        ] as [$symbol, $code, $message]) {
            try {
                $this->underAgent($this->agent, static function () use ($page, $symbol, $code): void {
                    $page->onAction('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
                        new HilosI18nCountryAddActionDTO('qy', $symbol, $code));
                });
                self::fail('Invalid currency should be refused');
            } catch (ValidationException $error) {
                self::assertSame($message, $error->getMessage());
            }
            self::assertArrayNotHasKey('qy', Hilos::$db->countries);
        }
    }

    public function testTrackedSuccessCarriesCustomCountryMessage(): void
    {
        $page = new CountriesListPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page): void {
            $page->beginActionDispatch('req');
            $page->onAction('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD,
                new HilosI18nCountryAddActionDTO('gg', '£', 'GBP'));
            $page->sendActionSuccess('country-ak', HilosSignalConstants::HILOS_I18N_COUNTRY_ADD, 'req');
            $page->endActionDispatch();
        });

        $successReplies = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::ACTION_SUCCESS) {
                continue;
            }
            if ($signal->data instanceof WebSocketSignalData
                && $signal->data->data instanceof PageActionSuccessSignalData) {
                $successReplies[] = $signal->data;
            }
        }

        self::assertCount(1, $successReplies);
        self::assertSame('country-ak', $successReplies[0]->targetAcceptKey);
        self::assertSame('req', $successReplies[0]->data->requestId);
        self::assertSame(
            'Custom country gg added and switched off for now.',
            $successReplies[0]->data->message,
        );
    }

    private function clearFixtures(): void
    {
        Database::sqlRun('DELETE FROM hilos_country WHERE code IN (?, ?)', ['gg', 'qy']);
        if ($this->createdUnitedStates) {
            Database::sqlRun('DELETE FROM hilos_country WHERE code = ?', ['us']);
        }
        Hilos::$db->countries->getObjectCollection()?->reHydrate();
        Hilos::$db->countries->clearCache();
    }
}
