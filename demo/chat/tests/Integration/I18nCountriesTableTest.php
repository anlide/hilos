<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportAnnounceDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountriesTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The countries table: every country, its name and locale, and a live window (HIL-1475). */
final class I18nCountriesTableTest extends IntegrationTestCase
{
    private const string SECOND = 'countries-second';
    private const string SEARCH_MISS = 'countries-search-miss';
    private const string SEARCH_HIT = 'countries-search-hit';
    private const string VIEWER = 'countries-viewer';

    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    /** @var list<string> Country codes this test created and must remove */
    private array $createdCountries = [];

    /** @var list<string> Language codes this test created and must remove */
    private array $createdLanguages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
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
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
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

    public function testTheWindowCarriesEveryCountryWithItsNameAndMarks(): void
    {
        $this->underAgent($this->agent, function (): void {
            $english = $this->language('en', 'English');
            $german = $this->language('de', 'Deutsch');
            $known = $this->country('us', '$', 'USD');
            $own = $this->country('qx', '¤', 'XQX');
            $named = $this->country('qy', '¤', 'XQY');
            Hilos::$db->countryNames->actions->createManual($named, $english, null, 'Qy Land');
            Hilos::$db->countryNames->actions->createManual($own, $german, null, 'Qxland auf Deutsch');
            Hilos::$db->countryNames->actions->createManual($own, $english, null, '');
            $named->actions->switchOn();

            $locale = Hilos::$db->locales->actions->create(
                $english,
                $own,
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            Hilos::$db->countryNames->actions->createManual($own, $english, $locale, 'Qx locale');
            $own->actions->update('¤', 'XQX', $locale->id);
            $this->fillPastFirstWindow();
        });

        $table = new HilosI18nCountriesTable();
        $sort = $table->defaultSort();
        $own = $this->rowByCode($table->getPage(new TableQueryDTO(search: 'qx', sort: $sort))->rows, 'qx');
        $named = $this->rowByCode($table->getPage(new TableQueryDTO(search: 'qy', sort: $sort))->rows, 'qy');
        $known = $this->rowByCode($table->getPage(new TableQueryDTO(search: 'us', sort: $sort))->rows, 'us');

        self::assertNull($own->name);
        self::assertSame('¤', $own->currencySymbol);
        self::assertSame('XQX', $own->currencyCode);
        self::assertSame('en-QX', $own->defaultLocaleCode);
        self::assertFalse($own->enabled);
        self::assertTrue($own->isOwn);
        self::assertNull(BuiltInI18nCatalog::country('qx'));
        self::assertSame('Qy Land', $named->name);
        self::assertNull($named->defaultLocaleCode);
        self::assertTrue($named->enabled);
        self::assertTrue($named->isOwn);
        self::assertFalse($known->isOwn);

        $byCode = $table->getPage(new TableQueryDTO(sort: $sort));
        self::assertSame(['qx', 'qy'], $this->picked($this->codes($byCode->rows), ['qx', 'qy']));

        $byName = $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosI18nCountriesTableRow::name)),
        ));
        // The framework filter sorts a null after a value on the way up, so a country with no name follows one that has one.
        self::assertSame(['qy', 'qx'], $this->picked($this->codes($byName->rows), ['qx', 'qy']));

        $byEnabled = $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosI18nCountriesTableRow::enabled)),
        ));
        self::assertSame(['qx', 'qy'], $this->picked($this->codes($byEnabled->rows), ['qx', 'qy']));

        self::assertSame(['qy'], $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'Qy Land'))->rows), ['qx', 'qy']));
        self::assertSame(['qy'], $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'XQY'))->rows), ['qx', 'qy']));
        self::assertSame(['qx'], $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'qx'))->rows), ['qx', 'qy']));
        self::assertSame([], $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'zzzz-absent'))->rows), ['qx', 'qy']));
        self::assertTrue($table->containsRow('qx', new TableQueryDTO(search: 'qx')));
        self::assertFalse($table->containsRow('qx', new TableQueryDTO(search: 'zzzz-absent')));
        self::assertFalse($table->containsRow('missing', new TableQueryDTO()));

        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: $table->windowSize()));
        self::assertSame(TableConstants::DEFAULT_WINDOW_SIZE, $table->windowSize());
        self::assertGreaterThan($table->windowSize(), $first->totalCount);
        self::assertCount($table->windowSize(), $first->rows);
        $firstCodes = $this->codes($first->rows);
        $ordered = $firstCodes;
        sort($ordered, SORT_STRING);
        self::assertSame($ordered, $firstCodes);
    }

    public function testACountryOrItsDefaultNameBecomesTheSameKindOfRowMutation(): void
    {
        $created = $this->underAgent($this->agent, function (): array {
            $english = $this->language('en', 'English');
            $german = $this->language('de', 'Deutsch');
            $own = $this->country('qx', '¤', 'XQX');
            $own->actions->update('#', 'XQA', null);
            $base = Hilos::$db->countryNames->actions->createManual($own, $english, null, 'Before');
            $base->actions->edit('After');
            $foreign = Hilos::$db->countryNames->actions->createManual($own, $german, null, 'Anders');

            return [
                'country' => $own->id,
                'name' => $base->id,
                'foreign' => $foreign->id,
                'countryId' => $own->id,
                'languageId' => $english->id,
                'foreignLanguageId' => $german->id,
            ];
        });
        $table = new HilosI18nCountriesTable();

        $create = $table->buildMutationForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::countries,
            (string) $created['country'],
            [],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $create);
        self::assertSame(TableMutationType::Create, $create->type);
        self::assertSame('qx', $create->rowKey);
        $createdRow = $create->row;
        self::assertInstanceOf(HilosI18nCountriesTableRow::class, $createdRow);
        self::assertSame('After', $createdRow->name);
        self::assertSame('XQA', $createdRow->currencyCode);
        self::assertTrue($createdRow->isOwn);

        $update = $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string) $created['country'],
            [],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $update);
        self::assertSame(TableMutationType::Update, $update->type);
        self::assertSame('qx', $update->rowKey);

        $nameUpdate = $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::countryNames,
            (string) $created['name'],
            [],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $nameUpdate);
        self::assertSame(TableMutationType::Update, $nameUpdate->type);
        self::assertSame('qx', $nameUpdate->rowKey);
        $namedRow = $nameUpdate->row;
        self::assertInstanceOf(HilosI18nCountriesTableRow::class, $namedRow);
        self::assertSame('After', $namedRow->name);

        $this->underAgent($this->agent, function () use ($created): void {
            Hilos::$db->countryNames[$created['name']]?->actions->delete();
        });
        $nameDelete = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::countryNames,
            (string) $created['name'],
            [
                ObjectCountryName::countryId => $created['countryId'],
                ObjectCountryName::languageId => $created['languageId'],
                ObjectCountryName::localeId => null,
            ],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $nameDelete);
        self::assertSame(TableMutationType::Update, $nameDelete->type);
        $cleared = $nameDelete->row;
        self::assertInstanceOf(HilosI18nCountriesTableRow::class, $cleared);
        self::assertNull($cleared->name);

        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::countryNames,
            (string) $created['foreign'],
            [
                ObjectCountryName::countryId => $created['countryId'],
                ObjectCountryName::languageId => $created['foreignLanguageId'],
                ObjectCountryName::localeId => null,
            ],
        )));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::locales,
            '1',
            [],
        )));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::languages,
            '1',
            [],
        )));

        $delete = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::countries,
            (string) $created['country'],
            [ObjectCountry::code => 'qx'],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $delete);
        self::assertSame(TableMutationType::Delete, $delete->type);
        self::assertSame('qx', $delete->rowKey);
        self::assertNull($delete->row);
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::countries,
            '1',
            [],
        )));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::countries,
            '999999',
            [],
        )));
    }

    public function testANewCountryReachesTheNextWindowAndOnlyASearchThatKeepsIt(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Countries table admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . uniqid('', true)), 0, 32));
        $session->actions->bindUser((int) $admin->id);
        foreach ([self::SECOND, self::SEARCH_MISS, self::SEARCH_HIT] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int) $admin->id, $session->token, (int) $session->id);
        }
        $table = new HilosI18nCountriesTable();
        $this->underAgent($this->agent, function () use ($table): void {
            $this->language('en', 'English');
            $this->fillPastFirstWindow();
            $filled = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()));
            self::assertGreaterThan($table->windowSize(), $filled->totalCount);
        });
        $total = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()))->totalCount;
        $sort = $table->defaultSort();

        $this->subscribe(self::SECOND, new TableWindowDescriptorDTO(
            sort: $sort,
            limit: $table->windowSize(),
            pageIndex: intdiv($total, $table->windowSize()),
        ));
        $this->subscribe(self::SEARCH_MISS, new TableWindowDescriptorDTO(
            filter: [TableConstants::FILTER_KEY_SEARCH => 'zzzz-absent'],
            sort: $sort,
            limit: $table->windowSize(),
        ));
        $this->subscribe(self::SEARCH_HIT, new TableWindowDescriptorDTO(
            filter: [TableConstants::FILTER_KEY_SEARCH => 'zz'],
            sort: $sort,
            limit: $table->windowSize(),
        ));
        $this->drain();

        $created = $this->underAgent($this->agent, function (): Country {
            return $this->country('zz', '¤', 'XZZ');
        });
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::countries,
            (string) $created->id,
            [ObjectCountry::code => 'zz'],
        ));
        Hilos::$browser->flushToSignalRouter();
        $live = $this->drain();

        self::assertTrue($this->namesRow($live, self::SECOND, 'zz'), $this->signalTrace($live));
        self::assertTrue($this->namesRow($live, self::SEARCH_HIT, 'zz'), $this->signalTrace($live));
        self::assertFalse($this->namesRow($live, self::SEARCH_MISS, 'zz'), $this->signalTrace($live));
    }

    public function testAViewerSeesEveryCountryField(): void
    {
        $this->underAgent($this->agent, function (): void {
            $this->language('en', 'English');
            $this->country('qx', '¤', 'XQX');
        });
        $viewer = Hilos::$db->users->actions->createWithName('Countries table viewer');
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . uniqid('', true)), 0, 32));
        $session->actions->bindUser((int) $viewer->id);
        Hilos::$rt->connections->actions->register(self::VIEWER, (int) $viewer->id, $session->token, (int) $session->id);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        try {
            $this->subscribe(self::VIEWER, null);
            $window = $this->windowOf($this->drain(), self::VIEWER);
            $rows = $window[TableWindowSignalData::rows];
            self::assertNotSame([], $rows);
            foreach ($rows as $row) {
                $country = $row[PagePayload::slots][HilosI18nCountriesTable::ROW_SLOT];
                foreach ([
                    HilosI18nCountriesTableRow::code,
                    HilosI18nCountriesTableRow::name,
                    HilosI18nCountriesTableRow::currencySymbol,
                    HilosI18nCountriesTableRow::currencyCode,
                    HilosI18nCountriesTableRow::defaultLocaleCode,
                    HilosI18nCountriesTableRow::enabled,
                    HilosI18nCountriesTableRow::isOwn,
                ] as $field) {
                    self::assertFalse(HiddenValue::isMark($country[$field]), $field);
                }
            }
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($rows, JSON_THROW_ON_ERROR));
        } finally {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        }
    }

    private function subscribe(string $acceptKey, ?TableWindowDescriptorDTO $window): void
    {
        $windows = $window === null ? [] : [ChatTableContext::hilosI18nCountries => $window];
        Hilos::$sr->subscribeToPage(
            CountriesListPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, CountriesListPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey): void {
            new CountriesListPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams([]));
        });
    }

    /**
     * Creates countries until the first window cannot hold them, leaving `zz` free for the live test.
     */
    private function fillPastFirstWindow(): void
    {
        $table = new HilosI18nCountriesTable();
        $total = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), limit: $table->windowSize()))->totalCount;
        $target = $table->windowSize() + 1;
        for ($left = ord('a'); $left <= ord('z') && $total < $target; $left++) {
            for ($right = ord('a'); $right <= ord('z') && $total < $target; $right++) {
                $code = chr($left) . chr($right);
                if ($code === 'zz' || Hilos::$db->countries[$code] !== null) {
                    continue;
                }
                $this->country($code, '¤', 'X' . strtoupper($code));
                $total++;
            }
        }
    }

    private function language(string $code, string $nativeName): Language
    {
        $language = Hilos::$db->languages[$code];
        if ($language !== null) {
            return $language;
        }
        $this->createdLanguages[] = $code;

        return Hilos::$db->languages->actions->create($code, $nativeName, false);
    }

    private function country(string $code, string $symbol, string $currency): Country
    {
        $country = Hilos::$db->countries[$code];
        if ($country !== null) {
            return $country;
        }
        $this->createdCountries[] = $code;

        return Hilos::$db->countries->actions->create($code, $symbol, $currency);
    }

    /**
     * @param list<HilosI18nCountriesTableRow> $rows Window rows
     * @return list<string> Country codes in window order
     */
    private function codes(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(HilosI18nCountriesTableRow::class, $row);
            $codes[] = $row->code;
        }

        return $codes;
    }

    /**
     * @param list<string> $codes Codes in window order
     * @param list<string> $wanted Codes to keep, in the order the window has them
     * @return list<string> Wanted codes in window order
     */
    private function picked(array $codes, array $wanted): array
    {
        return array_values(array_filter(
            $codes,
            static fn (string $code): bool => in_array($code, $wanted, true),
        ));
    }

    /**
     * @param list<HilosI18nCountriesTableRow> $rows Window rows
     */
    private function rowByCode(array $rows, string $code): HilosI18nCountriesTableRow
    {
        foreach ($rows as $row) {
            self::assertInstanceOf(HilosI18nCountriesTableRow::class, $row);
            if ($row->code === $code) {
                return $row;
            }
        }

        self::fail("Country {$code} is not in the window");
    }

    /**
     * @return list<array{accept: ?string, name: string, data: SignalDataInterface}> Queued signals
     */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData) {
                continue;
            }
            $signals[] = [
                'accept' => $signal->data->targetAcceptKey,
                'name' => $signal->signalName->getName(),
                'data' => $signal->data->data,
            ];
        }

        return $signals;
    }

    /**
     * @param list<array{accept: ?string, name: string, data: SignalDataInterface}> $signals Queued signals
     */
    private function namesRow(array $signals, string $acceptKey, string $code): bool
    {
        foreach ($signals as $signal) {
            if ($signal['accept'] !== $acceptKey) {
                continue;
            }
            $data = $signal['data'];
            if ($data instanceof TableViewportAnnounceDTO && $data->rowKey === $code) {
                return true;
            }
            if ($data instanceof TableViewportAppendDTO && ($data->row[PagePayload::rowKey] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{accept: ?string, name: string, data: SignalDataInterface}> $signals Queued signals
     */
    private function signalTrace(array $signals): string
    {
        $trace = [];
        foreach ($signals as $signal) {
            $accept = $signal['accept'] ?? 'unaddressed';
            $trace[] = $accept . ' ' . $signal['name'];
        }

        return implode(', ', $trace);
    }

    /**
     * @param list<array{accept: ?string, name: string, data: SignalDataInterface}> $signals Queued signals
     * @return array<string, mixed> Countries window of that connection
     */
    private function windowOf(array $signals, string $acceptKey): array
    {
        foreach ($signals as $signal) {
            if ($signal['accept'] !== $acceptKey || $signal['name'] !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(PageResponseSignalData::class, $signal['data']);
            $payload = $signal['data']->payload->toArray();
            if (!isset($payload[PagePayload::windows][ChatTableContext::hilosI18nCountries])) {
                continue;
            }

            return $payload[PagePayload::windows][ChatTableContext::hilosI18nCountries];
        }

        self::fail('The countries window was not delivered');
    }

    private function clearFixtures(): void
    {
        if ($this->createdCountries !== []) {
            $countries = $this->sqlCodes($this->createdCountries);
            Database::sqlRun("UPDATE hilos_country SET default_locale_id = NULL WHERE code IN ($countries)");
            Database::sqlRun(
                'DELETE FROM hilos_country_name WHERE country_id IN'
                . " (SELECT id FROM hilos_country WHERE code IN ($countries))",
            );
            Database::sqlRun(
                'DELETE FROM hilos_locale WHERE country_id IN'
                . " (SELECT id FROM hilos_country WHERE code IN ($countries))",
            );
            Database::sqlRun("DELETE FROM hilos_country WHERE code IN ($countries)");
        }
        if ($this->createdLanguages !== []) {
            $languages = $this->sqlCodes($this->createdLanguages);
            Database::sqlRun(
                'DELETE FROM hilos_language_name WHERE language_id IN'
                . " (SELECT id FROM hilos_language WHERE code IN ($languages))"
                . ' OR in_language_id IN'
                . " (SELECT id FROM hilos_language WHERE code IN ($languages))",
            );
            Database::sqlRun(
                'DELETE FROM hilos_country_name WHERE language_id IN'
                . " (SELECT id FROM hilos_language WHERE code IN ($languages))",
            );
            Database::sqlRun(
                'DELETE FROM hilos_locale WHERE language_id IN'
                . " (SELECT id FROM hilos_language WHERE code IN ($languages))",
            );
            Database::sqlRun("DELETE FROM hilos_language WHERE code IN ($languages)");
        }
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

    /**
     * @param list<string> $codes Two-letter codes this test itself minted
     * @return string Quoted SQL list
     */
    private function sqlCodes(array $codes): string
    {
        return implode(',', array_map(static fn (string $code): string => "'" . $code . "'", $codes));
    }
}
