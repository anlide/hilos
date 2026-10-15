<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\HilosException;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOffActionDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Locale removal through the real parser, ADMIN gate, item door and subscribed browser windows. */
final class I18nLocaleDeleteTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    /** @throws HilosException When fixture cleanup or ownership setup fails */
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
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        $this->clearFixtures();
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('fr', 'Français', false);
            Hilos::$db->countries->actions->create('gb', '£', 'GBP');
            foreach ([null, 'gb'] as $countryCode) {
                Hilos::$db->locales->actions->create(
                    Hilos::$db->languages['en'], Hilos::$db->countries[$countryCode],
                    'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                    'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
                );
            }
            Hilos::$db->languageNames->actions->createManual(
                Hilos::$db->languages['fr'], Hilos::$db->languages['en'], null, 'French',
            );
            Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['gb'], Hilos::$db->languages['en'], null, 'Britain',
            );
        });
        $this->connect('delete-ak', true);
    }

    /** @throws HilosException When fixture cleanup or restoration fails */
    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
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

    /** @return iterable<string, array{?string}> Locale address shapes */
    public static function addresses(): iterable
    {
        yield 'pair' => ['gb'];
        yield 'countryless' => [null];
    }

    /**
     * @param ?string $countryCode Country address or explicit null
     * @throws HilosException When the dispatcher or database fails
     */
    #[DataProvider('addresses')]
    public function testTrackedSuccessAndRepeatedDeletionIsAnUnknownLocale(?string $countryCode): void
    {
        $code = ObjectLocale::codeFor('en', $countryCode);
        $id = Hilos::$db->locales[$code]->id;
        $payload = [
            'languageId' => Hilos::$db->languages['en']->id,
            'countryId' => Hilos::$db->countries[$countryCode]?->id,
            'localeId' => $id,
        ];
        $this->assertSuccess($this->dispatch($payload));
        self::assertNull(Hilos::$db->locales[$code]);
        self::assertNull(Hilos::$db->locales[$id]);
        self::assertNotNull(Hilos::$db->locales[$countryCode === null ? 'en-GB' : 'en']);
        self::assertCount(1, Hilos::$db->languageNames);
        self::assertCount(1, Hilos::$db->countryNames);
        $before = $this->referenceRows();
        $failure = $this->dispatch($payload);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame('Unknown locale: ' . $id, $failure->reason);
        self::assertSame($before, $this->referenceRows());
    }

    /** @return iterable<string, array{string, string}> Current server refusal reasons */
    public static function refusals(): iterable
    {
        yield 'enabled' => ['enabled', "Locale 'en-GB' is switched on: switch it off before deleting it."];
        yield 'country default' => ['default', "Locale 'en-GB' is the country's default: choose another locale for the country first."];
        yield 'language override' => ['language', "Locale 'en-GB' has name overrides: remove them before deleting it."];
        yield 'country override' => ['country', "Locale 'en-GB' has name overrides: remove them before deleting it."];
    }

    /**
     * @param string $scenario Current protection to set after the page opens
     * @param string $reason Exact domain refusal
     * @throws HilosException When fixture setup, dispatch or inspection fails
     */
    #[DataProvider('refusals')]
    public function testLateProtectionIsRecheckedAndLeavesAllRowsAndCachesIntact(string $scenario, string $reason): void
    {
        $this->subscribe('delete-ak');
        $this->drain();
        $this->underAgent($this->agent, static function () use ($scenario): void {
            switch ($scenario) {
                case 'enabled':
                    Hilos::$db->locales['en-GB']->actions->switchOn();
                    break;
                case 'default':
                    Hilos::$db->countries['gb']->actions->update('£', 'GBP', Hilos::$db->locales['en-GB']->id);
                    Hilos::$db->countries['gb']->actions->switchOn();
                    break;
                case 'language':
                    Hilos::$db->languageNames->actions->createManual(
                        Hilos::$db->languages['fr'], Hilos::$db->languages['en'], Hilos::$db->locales['en-GB'], '',
                    );
                    break;
                case 'country':
                    Hilos::$db->countryNames->actions->createManual(
                        Hilos::$db->countries['gb'], Hilos::$db->languages['en'], Hilos::$db->locales['en-GB'], '',
                    );
                    break;
            }
        });
        $before = $this->referenceRows();
        $locale = Hilos::$db->locales['en-GB'];
        $failure = $this->dispatch([
            'languageId' => Hilos::$db->languages['en']->id,
            'countryId' => Hilos::$db->countries['gb']->id,
            'localeId' => Hilos::$db->locales['en-GB']->id,
        ]);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame($reason, $failure->reason);
        self::assertSame($before, $this->referenceRows());
        self::assertSame($locale, Hilos::$db->locales['en-GB']);
        self::assertTrue($locale->getObject()->isRelated());
    }

    /** @throws HilosException When malformed or missing addresses fail unexpectedly */
    public function testUnknownAndMalformedPayloadsAreTrackedFailures(): void
    {
        foreach ([
            ['languageId' => 999999, 'countryId' => Hilos::$db->countries['gb']->id, 'localeId' => 999999],
            ['languageId' => Hilos::$db->languages['en']->id, 'countryId' => 999999, 'localeId' => 999999],
            ['languageCode' => 'en'],
            ['languageCode' => 'EN', 'countryCode' => 'gb'],
            ['languageCode' => 'en', 'countryCode' => 42],
            [],
        ] as $payload) {
            $before = $this->referenceRows();
            $failure = $this->dispatch($payload);
            self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
            self::assertSame('fail', $failure->getEnvelopeOutcome());
            self::assertSame($before, $this->referenceRows());
        }
    }

    /** @throws HilosException When a different DTO reaches the page */
    public function testAnotherActionDtoIsRefusedBeforeTheWrite(): void
    {
        $before = $this->referenceRows();
        try {
            new LanguageLocalesPage($this->agent)->onAction(
                'delete-ak', HilosSignalConstants::HILOS_I18N_LOCALE_DELETE, new HilosI18nLocaleSwitchOffActionDTO(
                    Hilos::$db->languages['en']?->id ?? 999999,
                    Hilos::$db->countries['gb']?->id ?? 999999,
                    Hilos::$db->locales['en-GB']?->id ?? 999999,
                ),
            );
            self::fail('The deletion branch must reject a different DTO');
        } catch (InvalidActionPayloadException) {
            self::assertSame($before, $this->referenceRows());
        }
    }

    /** @return iterable<string, array{bool, bool}> Anonymous and signed-in viewers, with the mode on or off */
    public static function viewers(): iterable
    {
        yield 'anonymous viewer' => [false, true];
        yield 'member viewer' => [true, true];
        yield 'anonymous denied' => [false, false];
        yield 'member denied' => [true, false];
    }

    /**
     * @param bool $signedIn Whether the viewer has an account
     * @param bool $viewMode Whether ADMIN pages allow viewing
     * @throws HilosException When connection setup, subscription or dispatch fails
     */
    #[DataProvider('viewers')]
    public function testRealAdminGateRefusesDeletionWithoutWriting(bool $signedIn, bool $viewMode): void
    {
        $this->connect('viewer', $signedIn ? false : null);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set($viewMode);
        if ($viewMode) {
            $this->subscribe('viewer');
            $responses = array_values(array_filter($this->drain(),
                static fn (WebSocketSignalData $frame): bool => $frame->data instanceof PageResponseSignalData));
            self::assertCount(1, $responses);
            $payload = $responses[0]->data->payload->toArray();
            self::assertSame('en', $payload[PagePayload::data][LanguageCardBrowserData::DATA]['code']);
            self::assertNotEmpty($payload[PagePayload::windows][ChatTableContext::hilosI18nLanguageLocales][TableWindowSignalData::rows]);
        }
        $before = $this->referenceRows();
        $failure = $this->dispatch([
            'languageId' => Hilos::$db->languages['en']->id,
            'countryId' => Hilos::$db->countries['gb']->id,
            'localeId' => Hilos::$db->locales['en-GB']->id,
        ], 'viewer');
        self::assertInstanceOf(PageActionErrorSignalData::class, $failure);
        self::assertSame($viewMode ? 'view_mode' : ($signedIn ? 'forbidden' : 'unauthorized'), $failure->errorCode);
        self::assertNull($failure->errorType);
        self::assertNull($failure->errorDetail);
        self::assertSame($before, $this->referenceRows());
    }

    /** @throws HilosException When subscriptions, deletion or fan-out fails */
    public function testBothSubscribersKeepThePairAndReceiveTheEmptyLocaleAndReducedCardCount(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['gb'], Hilos::$db->languages['en'], Hilos::$db->locales['en'], 'Other edition',
            );
        });
        $otherLocale = Hilos::$db->locales['en']->getObject()->toArray();
        $names = [
            Database::sql('SELECT * FROM hilos_language_name ORDER BY id')->rows(),
            Database::sql('SELECT * FROM hilos_country_name ORDER BY id')->rows(),
        ];
        $this->connect('other-tab', true);
        foreach (['delete-ak', 'other-tab'] as $acceptKey) {
            $this->subscribe($acceptKey);
            $responses = array_values(array_filter($this->drain(),
                static fn (WebSocketSignalData $frame): bool => $frame->data instanceof PageResponseSignalData));
            self::assertCount(1, $responses);
            self::assertSame(2, $responses[0]->data->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA]['summary']['localeCount']);
        }
        $changes = new I18nLocaleDeletionChanges();
        SourceChangeBus::subscribe($changes);
        try {
            $this->assertSuccess($this->dispatch([
            'languageId' => Hilos::$db->languages['en']->id,
            'countryId' => Hilos::$db->countries['gb']->id,
            'localeId' => Hilos::$db->locales['en-GB']->id,
        ]));
        } finally {
            $changes->active = false;
        }
        self::assertCount(1, $changes->changes);
        self::assertSame(TableMutationType::Delete, $changes->changes[0]->mutationType);
        // Feed the real committed fact at the worker's browser-flush boundary.
        Hilos::$browser->record($changes->changes[0]);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->drain();
        foreach (['delete-ak', 'other-tab'] as $acceptKey) {
            $deltas = [];
            $cards = [];
            foreach ($frames as $frame) {
                if ($frame->targetAcceptKey !== $acceptKey) {
                    continue;
                }
                self::assertNotInstanceOf(TableWindowSignalData::class, $frame->data);
                if ($frame->data instanceof TableViewportDeltaDTO) {
                    $deltas[] = $frame->data;
                }
                if ($frame->data instanceof PageResponseSignalData) {
                    $cards[] = $frame->data->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA];
                }
            }
            self::assertCount(1, $deltas);
            self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $deltas[0]->kind);
            self::assertSame(Hilos::$db->languages['en']->id . ':' . Hilos::$db->countries['gb']->id, $deltas[0]->rowKey);
            $row = $deltas[0]->row[PagePayload::slots][HilosI18nLanguageLocalesTable::ROW_SLOT];
            self::assertSame('gb', $row['countryCode']);
            self::assertNull($row['localeCode']);
            self::assertNull($row['formats']);
            self::assertNull($row['enabled']);
            self::assertCount(1, $cards);
            self::assertSame(1, $cards[0][LanguageCardBrowserData::FIELD_SUMMARY]['localeCount']);
        }
        self::assertSame($otherLocale, Hilos::$db->locales['en']->getObject()->toArray());
        self::assertSame($names, [
            Database::sql('SELECT * FROM hilos_language_name ORDER BY id')->rows(),
            Database::sql('SELECT * FROM hilos_country_name ORDER BY id')->rows(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload Raw action body
     * @param string $acceptKey Initiating connection
     * @return PageActionSuccessSignalData|PageActionErrorSignalData Single correlated outcome
     * @throws HilosException When the dispatcher fails
     */
    private function dispatch(array $payload, string $acceptKey = 'delete-ak'): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $this->drain();
        $router = new PageSignalRouter(new HilosPageFactory($this->agent, Hilos::class), new ActionRouteConfig(Hilos::getPageActionRoutes()));
        $this->underAgent($this->agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO($acceptKey, HilosSignalConstants::HILOS_I18N_LOCALE_DELETE, $payload, 'delete-tracked'), 'websocket',
        ));
        $outcomes = [];
        foreach ($this->drain() as $frame) {
            if ($frame->data instanceof PageActionSuccessSignalData || $frame->data instanceof PageActionErrorSignalData) {
                self::assertSame($acceptKey, $frame->targetAcceptKey);
                $outcomes[] = $frame->data;
            }
        }
        self::assertCount(1, $outcomes);
        self::assertSame('delete-tracked', $outcomes[0]->requestId);
        self::assertSame(HilosSignalConstants::HILOS_I18N_LOCALE_DELETE, $outcomes[0]->action);
        return $outcomes[0];
    }

    /** @param PageActionSuccessSignalData|PageActionErrorSignalData $reply Tracked outcome */
    private function assertSuccess(PageActionSuccessSignalData|PageActionErrorSignalData $reply): void
    {
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame('Locale deleted.', $reply->message);
        self::assertNull($reply->reply);
        self::assertArrayNotHasKey('reply', $reply->toArray());
    }

    /**
     * @param string $acceptKey Connection to register
     * @param ?bool $admin Admin/member status, or null for an anonymous session
     * @throws HilosException When session setup fails
     */
    private function connect(string $acceptKey, ?bool $admin): void
    {
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', uniqid($acceptKey, true)), 0, 32));
        if ($admin !== null) {
            $user = Hilos::$db->users->actions->createWithName('Locale deletion reader');
            $user->actions->setAdmin($admin);
            $session->actions->bindUser($user->id);
        }
        Hilos::$rt->connections->actions->register($acceptKey, $session->userId, $session->token, $session->id);
    }

    /**
     * @param string $acceptKey Connection opening the locales page
     * @throws HilosException When subscription setup or delivery fails
     */
    private function subscribe(string $acceptKey): void
    {
        $params = ['languageCode' => 'en'];
        $windows = [
            ChatTableContext::hilosI18nLanguageLocales => new TableWindowDescriptorDTO(
                filter: [HilosI18nLanguageLocalesTable::FILTER_LANGUAGE => Hilos::$db->languages['en']?->id ?? 999999],
            ),
        ];
        Hilos::$sr->subscribeToPage(LanguageLocalesPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, LanguageLocalesPage::PAGE, $params, $windows));
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new LanguageLocalesPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
    }

    /** @return list<WebSocketSignalData> Browser frames since the last drain */
    private function drain(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = $signal->data;
            }
        }
        return $frames;
    }

    /**
     * SQL is an independent persistence oracle; mutations go through the page and item doors.
     *
     * @return array<string, list<array<string, mixed>>> All reference rows
     * @throws HilosException When a table cannot be read
     */
    private function referenceRows(): array
    {
        $rows = [];
        foreach (['hilos_language', 'hilos_country', 'hilos_locale', 'hilos_language_name', 'hilos_country_name'] as $table) {
            $rows[$table] = Database::sql("SELECT * FROM `{$table}` ORDER BY id")->rows();
        }
        return $rows;
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
            Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales,
            Hilos::$db->countries, Hilos::$db->languages,
        ] as $collection) {
            $collection->getObjectCollection()->reHydrate();
            $collection->clearCache();
        }
    }
}

/** Captures committed locale facts without inventing browser events in the test. */
final class I18nLocaleDeletionChanges implements SourceChangeSubscriberInterface
{
    public bool $active = true;

    /** @var list<SourceChange> Locale changes emitted while the action runs */
    public array $changes = [];

    /**
     * @param SourceChange $change Announced source change
     * @param SourceChangeProvenance $provenance Source origin (unused)
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->active && $change->sourceKey === HilosDbContext::locales) {
            $this->changes[] = $change;
        }
    }
}
