<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\CountryActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Locale;
use Hilos\HilosException;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Pages\I18n\Details\DTO\HilosI18nCountryDeleteActionDTO;
use Hilos\Pages\I18n\Lists\DTO\HilosI18nCountryRowDeleteActionDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Both page doors and direct item removal share the country's current deletion policy. */
final class I18nCountryDeleteTest extends IntegrationTestCase
{
    private I18nCountryServingWorker $worker;
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    /** @throws HilosException When fixture cleanup or ownership setup fails */
    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = new I18nCountryServingWorker();
        $this->agent = $this->worker->library();
        Hilos::initBrowser();
        Hilos::$browser->bindPageResender($this->worker);
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        foreach ([
            HilosDbContext::languages,
            HilosDbContext::countries,
            HilosDbContext::locales,
            HilosDbContext::languageNames,
            HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
    }

    /** @throws HilosException When fixture cleanup fails */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        foreach (['delete-ak', 'delete-second', 'delete-names'] as $acceptKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
        }
        ExecutionContext::setCurrentAgentId(null);
        ExecutionContext::setCurrentAcceptKey(null);
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        Hilos::$sr = null;
        parent::tearDown();
    }


    /** @return iterable<string, array{string, bool}> Free countries through every removal door */
    public static function freeCountries(): iterable
    {
        foreach (['item', 'list', 'card'] as $door) {
            yield $door . ' off' => [$door, false];
            yield $door . ' on' => [$door, true];
        }
    }

    /**
     * @param string $door Item or page removal door
     * @param bool $enabled Country switch position
     * @throws HilosException When the fixture or removal fails
     */
    #[DataProvider('freeCountries')]
    public function testFreeOwnCountryIsRemovedInEitherSwitchPosition(string $door, bool $enabled): void
    {
        $this->createFixture($enabled);
        $id = Hilos::$db->countries['qx']->id;
        $this->remove($door, 'qx');
        self::assertArrayNotHasKey('qx', Hilos::$db->countries);
        self::assertArrayNotHasKey($id, Hilos::$db->countries);
        self::assertSame(0, EntityCountry::count([EntityCountry::id => $id]));
        self::assertArrayHasKey('us', Hilos::$db->countries);
    }

    /** @throws HilosException When fixture creation or direct deletion fails */
    public function testDirectDeletionDoesNotRequireAConfiguredDefaultLanguage(): void
    {
        $this->createFixture();
        $id = Hilos::$db->countries['qx']->id;
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        $this->remove('item', 'qx');
        self::assertArrayNotHasKey($id, Hilos::$db->countries);
        self::assertSame(0, EntityCountry::count([EntityCountry::id => $id]));
    }

    /** @return iterable<string, array{string, string, string}> Protection reasons through all three doors */
    public static function refusals(): iterable
    {
        foreach (['item', 'list', 'card'] as $door) {
            foreach ([
                'known' => "Country 'us' cannot be deleted: the built-in catalog would bring it back.",
                'known with references' => "Country 'us' cannot be deleted: the built-in catalog would bring it back.",
                'locale off' => "Country 'qx' cannot be deleted: locales refer to it.",
                'locale on' => "Country 'qx' cannot be deleted: locales refer to it.",
                'base' => "Country 'qx' cannot be deleted: names refer to it.",
                'empty base' => "Country 'qx' cannot be deleted: names refer to it.",
                'foreign base' => "Country 'qx' cannot be deleted: names refer to it.",
                'catalog base' => "Country 'qx' cannot be deleted: names refer to it.",
                'override' => "Country 'qx' cannot be deleted: names refer to it.",
                'empty override' => "Country 'qx' cannot be deleted: names refer to it.",
            ] as $scenario => $message) {
                yield $door . ' ' . $scenario => [$door, $scenario, $message];
            }
        }
    }

    /**
     * @param string $door Item or page removal door
     * @param string $scenario Reference or catalog protection
     * @param string $message Exact refusal text
     * @throws HilosException When setup or refusal raises an unexpected failure
     */
    #[DataProvider('refusals')]
    public function testRefusalPreservesRowsAndCachedObjects(string $door, string $scenario, string $message): void
    {
        $this->createFixture();
        $code = str_starts_with($scenario, 'known') ? 'us' : 'qx';
        $country = Hilos::$db->countries[$code];
        $id = $country->id;
        self::assertTrue(Hilos::$db->countries->cardSummary(Hilos::$db->countries['qx']->id)->canDelete);
        $this->underAgent($this->agent, static function () use ($scenario, $code): void {
            switch ($scenario) {
                case 'known with references':
                case 'locale off':
                case 'locale on':
                    $locale = self::createLocale(Hilos::$db->countries[$code]);
                    if ($scenario === 'locale on') {
                        $locale->actions->switchOn();
                    }
                    Hilos::$db->countryNames->actions->createManual(
                        Hilos::$db->countries[$code], Hilos::$db->languages['en'], null, '',
                    );
                    break;
                case 'base':
                case 'empty base':
                case 'foreign base':
                    Hilos::$db->countryNames->actions->createManual(
                        Hilos::$db->countries[$code],
                        Hilos::$db->languages[$scenario === 'foreign base' ? 'de' : 'en'],
                        null,
                        $scenario === 'empty base' ? '' : 'Qxland',
                    );
                    break;
                case 'catalog base':
                    Hilos::$db->countryNames->actions->createCatalogBase(
                        Hilos::$db->countries[$code], Hilos::$db->languages['en'], 'Qxland',
                    );
                    break;
                case 'override':
                case 'empty override':
                    $locale = self::createLocale(null);
                    $base = Hilos::$db->countryNames->actions->createManual(
                        Hilos::$db->countries[$code], Hilos::$db->languages['en'], null, '',
                    );
                    Hilos::$db->countryNames->actions->createManual(
                        Hilos::$db->countries[$code], Hilos::$db->languages['en'], $locale,
                        $scenario === 'empty override' ? '' : 'Qx override',
                    );
                    // Leave an override alone: the normal base deletion also removes its overrides.
                    Database::sqlRun('DELETE FROM hilos_country_name WHERE id = ?', [$base->id]);
                    Hilos::$db->countryNames->getObjectCollection()->reHydrate();
                    Hilos::$db->countryNames->clearCache();
                    break;
            }
        });
        $before = Hilos::$db->countries->cardSummary($id)->toArray();
        $nameRows = array_map(static fn (EntityCountryName $row): array => $row->toArray(),
            EntityCountryName::get([EntityCountryName::country_id => $id])->toArray());
        $localeRows = array_map(static fn (EntityLocale $row): array => $row->toArray(),
            EntityLocale::get([EntityLocale::country_id => $id])->toArray());
        $cachedNames = [];
        foreach ($nameRows as $row) {
            $cachedNames[$row[EntityCountryName::id]] = Hilos::$db->countryNames[$row[EntityCountryName::id]];
        }
        $cachedLocales = [];
        foreach ($localeRows as $row) {
            $cachedLocales[$row[EntityLocale::id]] = Hilos::$db->locales[$row[EntityLocale::id]];
        }
        $this->remove($door, $code, $message);
        self::assertSame($country, Hilos::$db->countries[$id]);
        self::assertSame($country, Hilos::$db->countries[$code]);
        self::assertTrue($country->getObject()->isRelated());
        self::assertSame(1, EntityCountry::count([EntityCountry::id => $id]));
        self::assertSame($before, Hilos::$db->countries->cardSummary($id)->toArray());
        self::assertSame($nameRows, array_map(static fn (EntityCountryName $row): array => $row->toArray(),
            EntityCountryName::get([EntityCountryName::country_id => $id])->toArray()));
        self::assertSame($localeRows, array_map(static fn (EntityLocale $row): array => $row->toArray(),
            EntityLocale::get([EntityLocale::country_id => $id])->toArray()));
        foreach ($cachedNames as $nameId => $name) {
            self::assertSame($name, Hilos::$db->countryNames[$nameId]);
        }
        foreach ($cachedLocales as $localeId => $locale) {
            self::assertSame($locale, Hilos::$db->locales[$localeId]);
        }
    }

    /** @return iterable<string, array{bool}> Page action doors */
    public static function pageDoors(): iterable
    {
        yield 'list' => [true];
        yield 'card' => [false];
    }

    /**
     * @param bool $fromList Whether the request comes from the countries list
     * @throws HilosException When setup or action dispatch raises an unexpected failure
     */
    #[DataProvider('pageDoors')]
    public function testUnknownCountryAndMalformedPayloadAreTrackedFailures(bool $fromList): void
    {
        $this->createFixture();
        foreach ([['countryId' => 999999], ['countryId' => 'QX'], ['countryId' => 0], ['countryCode' => 'qx'], []] as $payload) {
            $reply = $this->dispatchDelete($fromList, $payload);
            self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
            self::assertSame('fail', $reply->getEnvelopeOutcome());
            self::assertSame('delete-tracked', $reply->requestId);
            if ($payload === ['countryId' => 999999]) {
                self::assertSame('Unknown country: 999999', $reply->reason);
            }
            self::assertArrayHasKey('qx', Hilos::$db->countries);
            self::assertArrayHasKey('us', Hilos::$db->countries);
        }
    }

    /**
     * @param bool $fromList Whether the request comes from the countries list
     * @throws HilosException When setup or action dispatch raises an unexpected failure
     */
    #[DataProvider('pageDoors')]
    public function testEachDoorRejectsTheOtherPagesDto(bool $fromList): void
    {
        $this->createFixture();
        $page = $fromList ? new CountriesListPage($this->agent) : new CountryDetailPage($this->agent);
        $dto = $fromList
            ? new HilosI18nCountryDeleteActionDTO(Hilos::$db->countries['qx']->id)
            : new HilosI18nCountryRowDeleteActionDTO(Hilos::$db->countries['qx']->id);
        try {
            $page->onAction('delete-ak', $this->action($fromList), $dto);
            self::fail('The other page DTO must be refused');
        } catch (InvalidActionPayloadException) {
            self::assertArrayHasKey('qx', Hilos::$db->countries);
        }
    }

    /** @throws HilosException When deletion or delivery of the subscribed cards fails */
    public function testBothSubscribedCardsReceiveTheDisappearance(): void
    {
        $this->createFixture();
        $this->registerAdmin();
        $this->registerSameAdmin('delete-second');
        $this->registerSameAdmin('delete-names');
        $countryId = Hilos::$db->countries['qx']->id;
        foreach (['delete-ak', 'delete-second'] as $acceptKey) {
            $this->subscribe($acceptKey, 'qx');
            $cards = $this->drainCardResponses();
            self::assertCount(1, $cards);
            self::assertSame('qx', $this->cardOf($cards[0])['code']);
            $this->assertBoundCountry($acceptKey, $countryId);
        }
        $this->subscribe('delete-names', 'qx', CountryNamesPage::class);
        $names = $this->drainCardResponses();
        self::assertCount(1, $names);
        self::assertSame('qx', $this->cardOf($names[0])['code']);
        $this->assertBoundCountry('delete-names', $countryId);

        $action = HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE;
        $this->worker->deliver(
            SignalTypeConstants::ACTION,
            new WebSocketActionSignalDTO('delete-ak', $action, ['countryId' => $countryId], 'delete-tracked'),
            $action,
        );
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertArrayNotHasKey($countryId, Hilos::$db->countries);

        $byKey = $this->drainByAcceptKey();
        $success = $this->soleSuccess($byKey['delete-ak'] ?? []);
        self::assertSame('delete-tracked', $success->requestId);
        self::assertSame('Country qx deleted.', $success->message);
        self::assertNull($success->reply);
        self::assertNull(Hilos::$sr?->pageResource('delete-ak'));
        self::assertFalse(Hilos::$sr?->hasPageDeliveryFailure('delete-ak'));
        $this->assertLiveDeletion($byKey['delete-second'] ?? [], CountryDetailPage::PAGE);
        $this->assertLiveDeletion($byKey['delete-names'] ?? [], CountryNamesPage::PAGE);
        self::assertNull(Hilos::$sr?->pageResource('delete-second'));
        self::assertNull(Hilos::$sr?->pageResource('delete-names'));
        self::assertTrue(Hilos::$sr?->hasPageDeliveryFailure('delete-second'));
        self::assertTrue(Hilos::$sr?->hasPageDeliveryFailure('delete-names'));

        $this->subscribe('delete-second', 'qx');
        $resent = $this->drainByAcceptKey()['delete-second'] ?? [];
        self::assertSame([], $this->framesNamed($resent, SignalTypeConstants::PAGE_RESPONSE));
        $errors = $this->framesNamed($resent, SignalConstants::SUBSCRIPTION_PAGE_ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $errors[0]->data->data);
        $this->assertPlainNotFound($errors[0]->data->data, CountryDetailPage::PAGE);
    }

    /** @throws HilosException When the fixture or removal raises an unexpected failure */
    public function testLateForeignKeyReferenceSurvivesAndDoesNotEvictTheCountry(): void
    {
        $this->createFixture();
        $this->registerAdmin();
        $this->subscribe('delete-ak', 'qx');
        self::assertCount(1, $this->drainCardResponses());
        $cached = Hilos::$db->countries['qx'];
        $this->assertBoundCountry('delete-ak', $cached->id);
        $late = I18nCountryDeleteLateReference::fromEntity(EntityCountry::get([EntityCountry::id => $cached->id])->first());
        $view = new Country($late);
        $view->setCollection(Hilos::$db->countries);
        self::assertTrue(Hilos::$db->countries->cardSummary($cached->id)->canDelete);
        try {
            $this->underAgent($this->agent, static fn () => new CountryActions($view)->delete());
            self::fail('The FK must refuse a reference arriving after the canonical check');
        } catch (ForeignKeyConstraintException $failure) {
            self::assertStringContainsString('hilos_country_name', $failure->getMessage());
        }
        self::assertSame($cached, Hilos::$db->countries[$cached->id]);
        self::assertTrue($cached->getObject()->isRelated());
        self::assertTrue($late->isRelated());
        self::assertSame(1, EntityCountry::count([EntityCountry::id => $cached->id]));
        self::assertSame('Late reference', Hilos::$db->countryNames->findBase($cached->id, Hilos::$db->languages['en']->id)->name);
        self::assertSame(1, EntityCountryName::count([EntityCountryName::country_id => $cached->id]));
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame(
            [],
            $this->framesNamed($this->drainByAcceptKey()['delete-ak'] ?? [], SignalConstants::SUBSCRIPTION_PAGE_ERROR),
        );
        $this->assertBoundCountry('delete-ak', $cached->id);
    }

    /** @throws HilosException When a recreated country is refused for the row it replaced */
    public function testARecreatedCountryDoesNotReceiveTheOldDelete(): void
    {
        $this->createFixture();
        $this->registerAdmin();
        $oldId = Hilos::$db->countries['qx']->id;
        $this->subscribe('delete-ak', 'qx');
        self::assertCount(1, $this->drainCardResponses());
        $this->assertBoundCountry('delete-ak', $oldId);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['qx']->actions->delete();
            Hilos::$db->countries->actions->create('qx', '¤', 'XQX');
        });
        $newId = Hilos::$db->countries['qx']->id;
        self::assertNotSame($oldId, $newId);
        $this->registerSameAdmin('delete-second');
        $this->subscribe('delete-second', 'qx');
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $byKey = $this->drainByAcceptKey();
        $this->assertLiveDeletion($byKey['delete-ak'] ?? [], CountryDetailPage::PAGE);
        self::assertNull(Hilos::$sr?->pageResource('delete-ak'));
        $recreated = $byKey['delete-second'] ?? [];
        self::assertSame([], $this->framesNamed($recreated, SignalConstants::SUBSCRIPTION_PAGE_ERROR));
        $this->assertBoundCountry('delete-second', $newId);
        $responses = $this->framesNamed($recreated, SignalTypeConstants::PAGE_RESPONSE);
        self::assertNotEmpty($responses);
        self::assertInstanceOf(WebSocketSignalData::class, $responses[0]->data);
        self::assertInstanceOf(PageResponseSignalData::class, $responses[0]->data->data);
        self::assertSame('qx', $this->cardOf($responses[0]->data->data)['code']);
    }

    /**
     * @param bool $enabled Whether the own country starts switched on
     * @throws HilosException When fixture creation or switching fails
     */
    private function createFixture(bool $enabled = false): void
    {
        $this->underAgent($this->agent, static function () use ($enabled): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('de', 'Deutsch', false);
            Hilos::$db->countries->actions->create('us', '$', 'USD');
            Hilos::$db->countries->actions->create('qx', '¤', 'XQX');
            if ($enabled) {
                Hilos::$db->countries['qx']->actions->switchOn();
            }
        });
    }

    /**
     * @param ?Country $country Country of the locale, or null for the countryless one
     * @return Locale New disabled locale
     * @throws HilosException When locale creation fails
     */
    private static function createLocale(?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            Hilos::$db->languages['en'], $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
        );
    }

    /**
     * @param string $door Item or page removal door
     * @param string $code Country address
     * @param ?string $refusal Expected business failure, or null for success
     * @throws HilosException When removal raises an unexpected failure
     */
    private function remove(string $door, string $code, ?string $refusal = null): void
    {
        if ($door === 'item') {
            try {
                $this->underAgent($this->agent, static fn () => Hilos::$db->countries[$code]->actions->delete());
                self::assertNull($refusal);
            } catch (ValidationException $failure) {
                self::assertSame($refusal, $failure->getMessage());
            }
            return;
        }
        $reply = $this->dispatchDelete(
            $door === 'list',
            ['countryId' => isset(Hilos::$db->countries[$code]) ? Hilos::$db->countries[$code]->id : 999999],
        );
        if ($refusal !== null) {
            self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
            self::assertSame('fail', $reply->getEnvelopeOutcome());
            self::assertSame($refusal, $reply->reason);
        } else {
            self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
            self::assertSame('Country qx deleted.', $reply->message);
            self::assertNull($reply->reply);
            self::assertArrayNotHasKey('reply', $reply->toArray());
        }
        self::assertSame($this->action($door === 'list'), $reply->action);
        self::assertSame('delete-tracked', $reply->requestId);
    }

    /**
     * @param bool $fromList Whether the request comes from the list
     * @param array<string, mixed> $payload Raw action payload
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Tracked dispatcher outcome
     * @throws HilosException When administrator setup or dispatch fails
     */
    private function dispatchDelete(bool $fromList, array $payload): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $this->registerAdmin();
        $action = $this->action($fromList);
        $page = $fromList ? CountriesListPage::PAGE : CountryDetailPage::PAGE;
        $router = new PageSignalRouter(new HilosPageFactory($this->agent, Hilos::class), new ActionRouteConfig([$action => $page]));
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO('delete-ak', $action, $payload, 'delete-tracked'), 'websocket',
        ));
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData
                && ($signal->data->data instanceof PageActionSuccessSignalData || $signal->data->data instanceof PageActionErrorSignalData)
            ) {
                return $signal->data->data;
            }
        }
        self::fail('The tracked delete did not answer');
    }

    /** @throws HilosException When administrator session setup fails */
    private function registerAdmin(): void
    {
        if (Hilos::$rt->connections['delete-ak'] !== null) {
            return;
        }
        $admin = Hilos::$db->users->actions->createWithName('Country delete admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . uniqid('', true)), 0, 32));
        $session->actions->bindUser($admin->id);
        Hilos::$rt->connections->actions->register('delete-ak', $admin->id, $session->token, $session->id);
    }

    /** @throws HilosException When the second connection cannot join the administrator's session */
    private function registerSameAdmin(string $acceptKey): void
    {
        Hilos::$rt->connections->actions->register(
            $acceptKey,
            Hilos::$rt->connections['delete-ak']->userId,
            Hilos::$rt->connections['delete-ak']->sessionToken,
            Hilos::$rt->connections['delete-ak']->sessionId,
        );
    }

    /**
     * @param bool $fromList Whether the request comes from the list
     * @return string Page-owned action name
     */
    private function action(bool $fromList): string
    {
        return $fromList ? HilosSignalConstants::HILOS_I18N_COUNTRY_ROW_DELETE : HilosSignalConstants::HILOS_I18N_COUNTRY_DELETE;
    }

    /** @return list<PageResponseSignalData> Card responses queued since the last call */
    private function drainCardResponses(): array
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

    /**
     * @param PageResponseSignalData $response Subscription or live page response
     * @return array<string, mixed> Card wire fields
     */
    private function cardOf(PageResponseSignalData $response): array
    {
        return $response->payload->toArray()[PagePayload::data][CountryCardBrowserData::DATA];
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param string $code Country route address
     * @param class-string<CountryDetailPage|CountryNamesPage> $pageClass Page class to subscribe to
     * @throws HilosException When subscription setup or its response fails
     */
    private function subscribe(
        string $acceptKey,
        string $code,
        string $pageClass = CountryDetailPage::class,
    ): void {
        $this->worker->deliver(
            SignalTypeConstants::PAGE_SUBSCRIBE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, ['countryCode' => $code]),
            $pageClass::PAGE,
        );
    }

    /**
     * @param string $acceptKey Connection whose answer named the country
     * @param int|string $countryId Primary id that answer bound
     */
    private function assertBoundCountry(string $acceptKey, int|string $countryId): void
    {
        $resource = Hilos::$sr?->pageResource($acceptKey);
        self::assertNotNull($resource);
        self::assertSame(HilosDbContext::countries, $resource->collectionKey);
        self::assertSame((string) $countryId, $resource->idString);
    }

    /**
     * @return array<string, list<SignalDTO>> Frames addressed to a connection, in queue order
     */
    private function drainByAcceptKey(): array
    {
        $byKey = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData || $signal->data->targetAcceptKey === null) {
                continue;
            }
            $byKey[$signal->data->targetAcceptKey][] = $signal;
        }

        return $byKey;
    }

    /**
     * @param list<SignalDTO> $signals Frames addressed to one connection
     * @param string $name Signal name to keep
     * @return list<SignalDTO> Frames of that name, in the order they were queued
     */
    private function framesNamed(array $signals, string $name): array
    {
        return array_values(array_filter(
            $signals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name,
        ));
    }

    /**
     * @param list<SignalDTO> $signals Frames addressed to the initiator
     * @return PageActionSuccessSignalData The one tracked acknowledgement
     */
    private function soleSuccess(array $signals): PageActionSuccessSignalData
    {
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::ACTION_SUCCESS, $signals[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $signals[0]->data->data);

        return $signals[0]->data->data;
    }

    /**
     * @param list<SignalDTO> $signals Frames addressed to one connection
     * @param string $page Page the refusal names
     */
    private function assertLiveDeletion(array $signals, string $page): void
    {
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $signals[0]->data->data);
        $missing = new PageResourceNotFoundException();
        $text = PageErrorMessages::forStatus($missing->httpCode);
        self::assertSame([
            'page' => $page,
            'httpCode' => $missing->httpCode,
            'errorCode' => $missing->errorCode,
            'title' => $text->title,
            'message' => $text->message,
            PageSubscriptionErrorSignalData::transitionMessage => PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        ], $signals[0]->data->data->toArray());
    }

    /**
     * @param PageSubscriptionErrorSignalData $error Refusal of a subscribe to a missing country
     * @param string $page Page the refusal names
     */
    private function assertPlainNotFound(PageSubscriptionErrorSignalData $error, string $page): void
    {
        $missing = new PageResourceNotFoundException();
        $text = PageErrorMessages::forStatus($missing->httpCode);
        self::assertSame([
            'page' => $page,
            'httpCode' => $missing->httpCode,
            'errorCode' => $missing->errorCode,
            'title' => $text->title,
            'message' => $text->message,
        ], $error->toArray());
    }

    /** @throws HilosException When fixture cleanup or cache hydration fails */
    private function clearFixtures(): void
    {
        Database::sqlRun('UPDATE hilos_country SET default_locale_id = NULL');
        Database::sqlRun('DELETE FROM hilos_country_name');
        Database::sqlRun('DELETE FROM hilos_language_name');
        Database::sqlRun('DELETE FROM hilos_locale');
        Database::sqlRun('DELETE FROM hilos_country');
        Database::sqlRun('DELETE FROM hilos_i18n_reflow');
        Database::sqlRun('DELETE FROM hilos_language');
        foreach ([
            Hilos::$db->countryNames,
            Hilos::$db->languageNames,
            Hilos::$db->locales,
            Hilos::$db->countries,
            Hilos::$db->languages,
        ] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}


/** Inserts a real FK reference exactly between the canonical check and the SQL delete. */
final class I18nCountryDeleteLateReference extends ObjectCountry
{
    /** @throws HilosException When the late write or the FK-protected removal fails */
    public function delete(): void
    {
        Hilos::$db->countryNames->actions->createManual(
            Hilos::$db->countries[$this->id], Hilos::$db->languages['en'], null, 'Late reference',
        );
        parent::delete();
    }
}
