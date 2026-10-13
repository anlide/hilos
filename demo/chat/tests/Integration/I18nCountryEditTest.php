<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryUpdateActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowUpdateActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Both country edit entrances use the same owned write door and tracked acknowledgements. */
final class I18nCountryEditTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'country-edit-admin';

    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;
    private bool $createdEnglish = false;

    /** @var list<string> Countries created only for this case */
    private array $createdCountries = [];

    /** @var list<string> Locales created only for this case */
    private array $createdLocales = [];

    /** @var array{symbol: string, currency: string, localeId: ?int, enabled: bool} Ireland's values before this case */
    private array $previousIreland;

    /** Claims the i18n rows and creates only fixtures missing from the integration database. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach ([HilosDbContext::languages, HilosDbContext::countries, HilosDbContext::locales,
            HilosDbContext::languageNames, HilosDbContext::countryNames] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->underAgent($this->agent, function (): void {
            if (Hilos::$db->languages['en'] === null) {
                Hilos::$db->languages->actions->create('en', 'English', false);
                $this->createdEnglish = true;
            }
            foreach (['ie' => ['€', 'EUR'], 'qz' => ['¤', 'XQZ']] as $code => [$symbol, $currency]) {
                if (Hilos::$db->countries[$code] === null) {
                    Hilos::$db->countries->actions->create($code, $symbol, $currency);
                    $this->createdCountries[] = $code;
                }
            }
            $this->previousIreland = [
                'symbol' => Hilos::$db->countries['ie']->currencySymbol,
                'currency' => Hilos::$db->countries['ie']->currencyCode,
                'localeId' => Hilos::$db->countries['ie']->defaultLocaleId,
                'enabled' => Hilos::$db->countries['ie']->enabled,
            ];
            Hilos::$db->countries['ie']->actions->switchOff();
            Hilos::$db->countries['ie']->actions->update('€', 'EUR', null);
            foreach (['ie', 'qz'] as $country) {
                $code = 'en-' . strtoupper($country);
                if (Hilos::$db->locales[$code] === null) {
                    Hilos::$db->locales->actions->create(
                        Hilos::$db->languages['en'], Hilos::$db->countries[$country],
                        'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                        'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
                    );
                    $this->createdLocales[] = $code;
                }
            }
        });
    }

    /** Restores the original country values and removes only rows this case created. */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->underAgent($this->agent, function (): void {
            Hilos::$db->countries['ie']->actions->switchOff();
            Hilos::$db->countries['ie']->actions->update(
                $this->previousIreland['symbol'], $this->previousIreland['currency'], $this->previousIreland['localeId'],
            );
            if ($this->previousIreland['enabled']) {
                Hilos::$db->countries['ie']->actions->switchOn();
            }
            foreach ($this->createdLocales as $code) {
                Hilos::$db->locales[$code]->actions->delete();
            }
        });
        // Known countries cannot be deleted through the application write door.
        foreach ($this->createdCountries as $code) {
            Database::sqlRun('DELETE FROM hilos_country WHERE code = ?', [$code]);
        }
        Hilos::$db->countries->getObjectCollection()?->reHydrate();
        Hilos::$db->countries->clearCache();
        if ($this->createdEnglish) {
            Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code = 'en')");
            Database::sqlRun("DELETE FROM hilos_language WHERE code = 'en'");
            foreach ([Hilos::$db->countryNames, Hilos::$db->languages] as $collection) {
                $collection->getObjectCollection()?->reHydrate();
                $collection->clearCache();
            }
        }
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        Hilos::$sr = null;
        parent::tearDown();
    }

    /** Saves through the card, then clears the same default locale through its table row. */
    public function testCardSavesCurrencyAndLocaleAndListClearsTheLocale(): void
    {
        $this->save(false, 'ie', 'IE€', 'EUR', 'en-IE');
        self::assertSame('IE€', Hilos::$db->countries['ie']->currencySymbol);
        self::assertSame('EUR', Hilos::$db->countries['ie']->currencyCode);
        self::assertSame(Hilos::$db->locales['en-IE']->id, Hilos::$db->countries['ie']->defaultLocaleId);
        self::assertFalse(Hilos::$db->countries['ie']->enabled);
        $this->save(true, 'ie', '€', 'EUR', null);
        $this->assertUnchanged(false);
    }

    /** @return iterable<string, array{bool}> Both edit entrances */
    public static function doors(): iterable
    {
        yield 'card' => [false];
        yield 'row' => [true];
    }

    /** @param bool $fromList Whether the request comes from the table */
    #[DataProvider('doors')]
    public function testEnabledCountryIsRefusedWithoutAWrite(bool $fromList): void
    {
        $this->underAgent($this->agent, static fn () => Hilos::$db->countries['ie']->actions->switchOn());
        try {
            $this->save($fromList, 'ie', 'IE€', 'USD', 'en-IE');
            self::fail('An enabled country must refuse edits');
        } catch (I18nRowFrozenException $error) {
            self::assertSame("Country 'ie' is switched on: switch it off, edit it, switch it on", $error->getMessage());
        }
        $this->assertUnchanged(true);
    }

    /** @return iterable<string, array{bool, string, string, ?string, string}> Invalid edits through both entrances */
    public static function invalidEdits(): iterable
    {
        foreach (self::doors() as $door => [$fromList]) {
            yield $door . ' empty symbol' => [$fromList, '', 'EUR', null, 'Country currency symbol must have 1..8 characters'];
            yield $door . ' long symbol' => [$fromList, '€€€€€€€€€', 'EUR', null, 'Country currency symbol must have 1..8 characters'];
            yield $door . ' currency' => [$fromList, 'IE€', 'EU', null, 'Country currency code must have three uppercase letters'];
            yield $door . ' foreign locale' => [$fromList, 'IE€', 'EUR', 'en-QZ', 'Country default locale must belong to this country'];
            yield $door . ' removed locale' => [$fromList, 'IE€', 'EUR', 'en-ZZ', "Locale 'en-ZZ' no longer exists."];
        }
    }

    /**
     * @param bool $fromList Whether the request comes from the table
     * @param string $symbol Proposed symbol
     * @param string $currency Proposed currency
     * @param ?string $locale Proposed default locale
     * @param string $message Expected refusal
     */
    #[DataProvider('invalidEdits')]
    public function testInvalidEditIsRefusedWithoutAWrite(bool $fromList, string $symbol, string $currency, ?string $locale, string $message): void
    {
        try {
            $this->save($fromList, 'ie', $symbol, $currency, $locale);
            self::fail('The invalid edit must be refused');
        } catch (ValidationException $error) {
            self::assertSame($message, $error->getMessage());
        }
        $this->assertUnchanged(false);
    }

    /** @param bool $fromList Whether the request comes from the table */
    #[DataProvider('doors')]
    public function testUnknownCountryIsRefused(bool $fromList): void
    {
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown country: zz');
        $this->save($fromList, 'zz', '€', 'EUR', null);
    }

    /** @param bool $fromList Whether the request comes from the table */
    #[DataProvider('doors')]
    public function testTheOtherDoorsDtoIsRefused(bool $fromList): void
    {
        $page = $fromList ? new CountriesListPage($this->agent) : new CountryDetailPage($this->agent);
        $action = $fromList ? HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_UPDATE : HilosSignalConstants::HILOS_I18N_COUNTRY_UPDATE;
        $dto = $fromList ? new HilosI18nCountryUpdateActionDTO('ie', 'IE€', 'EUR', 'en-IE')
            : new HilosI18nCountryRowUpdateActionDTO('ie', 'IE€', 'EUR', 'en-IE');
        try {
            $this->underAgent($this->agent, static fn () => $page->onAction(self::ACCEPT_KEY, $action, $dto));
            self::fail('The entrance must refuse the other DTO');
        } catch (InvalidActionPayloadException) {
            $this->assertUnchanged(false);
        }
    }

    /** Exercises the dispatcher so both tracked replies carry their request ids and the validation refusal's text. */
    public function testTrackedRouterRepliesWithSuccessAndFailureAndPreservesTheSavedRow(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Country edit administrator');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int) $admin->id);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, (int) $admin->id, $session->token, (int) $session->id);
        $router = new PageSignalRouter(new HilosPageFactory($this->agent, Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
        $dto = new HilosI18nCountryUpdateActionDTO('ie', 'IE€', 'EUR', 'en-IE');
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, $dto->getAction(), $dto->toArray(), 'country-save'), 'websocket',
        ));
        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('country-save', $success->requestId);
        self::assertSame($dto->getAction(), $success->action);
        self::assertSame('Country saved.', $success->message);
        self::assertNull($success->reply);

        $invalid = new HilosI18nCountryUpdateActionDTO('ie', '$', 'USD', 'en-ZZ');
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO(self::ACCEPT_KEY, $invalid->getAction(), $invalid->toArray(), 'country-fail'), 'websocket',
        ));
        $failed = $this->acknowledgement(SignalConstants::ACTION_ERROR);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failed);
        self::assertSame('country-fail', $failed->requestId);
        self::assertSame($invalid->getAction(), $failed->action);
        self::assertSame("Locale 'en-ZZ' no longer exists.", $failed->reason);
        self::assertNull($failed->errorType);
        self::assertNull($failed->errorDetail);
        self::assertSame('IE€', Hilos::$db->countries['ie']->currencySymbol);
        self::assertSame('EUR', Hilos::$db->countries['ie']->currencyCode);
        self::assertSame(Hilos::$db->locales['en-IE']->id, Hilos::$db->countries['ie']->defaultLocaleId);
    }

    /**
     * @param bool $fromList Whether the request comes from the table
     * @param string $code Country to update
     * @param string $symbol Proposed symbol
     * @param string $currency Proposed currency
     * @param ?string $locale Proposed default locale
     */
    private function save(bool $fromList, string $code, string $symbol, string $currency, ?string $locale): void
    {
        $page = $fromList ? new CountriesListPage($this->agent) : new CountryDetailPage($this->agent);
        $dto = $fromList ? new HilosI18nCountryRowUpdateActionDTO($code, $symbol, $currency, $locale)
            : new HilosI18nCountryUpdateActionDTO($code, $symbol, $currency, $locale);
        $this->underAgent($this->agent, static fn () => self::assertNull($page->onAction(self::ACCEPT_KEY, $dto->getAction(), $dto)));
    }

    /** @param bool $enabled Expected country switch */
    private function assertUnchanged(bool $enabled): void
    {
        self::assertSame('€', Hilos::$db->countries['ie']->currencySymbol);
        self::assertSame('EUR', Hilos::$db->countries['ie']->currencyCode);
        self::assertNull(Hilos::$db->countries['ie']->defaultLocaleId);
        self::assertSame($enabled, Hilos::$db->countries['ie']->enabled);
    }

    /**
     * @param string $name Acknowledgement signal to find
     * @return SignalDataInterface Single acknowledgement delivered to the acting connection
     */
    private function acknowledgement(string $name): SignalDataInterface
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                $frames[] = $signal->data->data;
            }
        }
        self::assertCount(1, $frames);

        return $frames[0];
    }
}
