<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Lists\LanguagesListPage;
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
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The languages table: every language, its marks, and a live window (HIL-1474). */
final class I18nLanguagesTableTest extends IntegrationTestCase
{
    private const string OPEN = 'languages-open';
    private const string SEARCH_MISS = 'languages-search-miss';
    private const string SEARCH_HIT = 'languages-search-hit';
    private const string VIEWER = 'languages-viewer';

    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

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
        $this->clearFixtures();
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

    public function testTheWindowCarriesEveryLanguageWithItsMarks(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Qx own tongue', true);
            Hilos::$db->languages['en']->actions->switchOn();
        });

        $table = new HilosI18nLanguagesTable();
        $rows = $table->getPage(new TableQueryDTO(sort: $table->defaultSort()));
        $english = $this->rowByCode($rows->rows, 'en');
        $own = $this->rowByCode($rows->rows, 'qx');

        self::assertSame('English', $english->nativeName);
        self::assertFalse($english->rtl);
        self::assertTrue($english->enabled);
        self::assertTrue($english->isDefault);
        self::assertFalse($english->isOwn);
        self::assertSame('Qx own tongue', $own->nativeName);
        self::assertTrue($own->rtl);
        self::assertFalse($own->enabled);
        self::assertFalse($own->isDefault);
        self::assertTrue($own->isOwn);
        self::assertSame(['en', 'qx'], $this->picked($this->codes($rows->rows), ['en', 'qx']));

        $byEnabled = $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(HilosI18nLanguagesTableRow::enabled)),
        ));
        $enabledOrder = $this->picked($this->codes($byEnabled->rows), ['en', 'qx']);
        self::assertSame(['qx', 'en'], $enabledOrder);

        self::assertSame(['qx'], $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'qx'))->rows), ['en', 'qx']));
        self::assertSame(
            ['qx'],
            $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'own tongue'))->rows), ['en', 'qx']),
        );
        self::assertSame(
            [],
            $this->picked($this->codes($table->getPage(new TableQueryDTO(search: 'zzzz-absent'))->rows), ['en', 'qx']),
        );
        self::assertTrue($table->containsRow('qx', new TableQueryDTO(search: 'qx')));
        self::assertFalse($table->containsRow('qx', new TableQueryDTO(search: 'zzzz-absent')));
        self::assertFalse($table->containsRow('missing', new TableQueryDTO()));
    }

    public function testALanguageChangeBecomesTheSameKindOfRowMutation(): void
    {
        $created = $this->underAgent($this->agent, static function () {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $own = Hilos::$db->languages->actions->create('qx', 'Before', false);
            $own->actions->update('After', true);

            return ['en' => $english->id, 'qx' => $own->id];
        });
        $table = new HilosI18nLanguagesTable();

        $create = $table->buildMutationForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::languages,
            (string) $created['qx'],
            [],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $create);
        self::assertSame(TableMutationType::Create, $create->type);
        self::assertSame('qx', $create->rowKey);
        $createdRow = $create->row;
        self::assertInstanceOf(HilosI18nLanguagesTableRow::class, $createdRow);
        self::assertSame('After', $createdRow->nativeName);
        self::assertTrue($createdRow->rtl);
        self::assertTrue($createdRow->isOwn);

        $update = $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::languages,
            (string) $created['en'],
            [],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $update);
        self::assertSame(TableMutationType::Update, $update->type);
        self::assertSame('en', $update->rowKey);
        $updatedRow = $update->row;
        self::assertInstanceOf(HilosI18nLanguagesTableRow::class, $updatedRow);
        self::assertTrue($updatedRow->isDefault);

        $delete = $table->buildMutationForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::languages,
            (string) $created['qx'],
            [ObjectLanguage::code => 'qx'],
        ));
        self::assertInstanceOf(TableRowMutationDTO::class, $delete);
        self::assertSame(TableMutationType::Delete, $delete->type);
        self::assertSame('qx', $delete->rowKey);
        self::assertNull($delete->row);

        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::languages,
            '999999',
            [],
        )));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::languages,
            '1',
            [],
        )));
        self::assertNull($table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::countries,
            '1',
            [],
        )));
    }

    public function testANewLanguageReachesAnOpenWindowAndOnlyASearchThatKeepsIt(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Languages table admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . uniqid('', true)), 0, 32));
        $session->actions->bindUser((int) $admin->id);
        foreach ([self::OPEN, self::SEARCH_MISS, self::SEARCH_HIT] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int) $admin->id, $session->token, (int) $session->id);
        }
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
        });

        $sort = (new HilosI18nLanguagesTable())->defaultSort();
        $this->subscribe(self::OPEN, null);
        $this->subscribe(self::SEARCH_MISS, new TableWindowDescriptorDTO(
            filter: [TableConstants::FILTER_KEY_SEARCH => 'zzzz-absent'],
            sort: $sort,
            limit: (new HilosI18nLanguagesTable())->windowSize(),
        ));
        $this->subscribe(self::SEARCH_HIT, new TableWindowDescriptorDTO(
            filter: [TableConstants::FILTER_KEY_SEARCH => 'zz'],
            sort: $sort,
            limit: (new HilosI18nLanguagesTable())->windowSize(),
        ));
        $this->drain();

        $created = $this->underAgent($this->agent, static function () {
            return Hilos::$db->languages->actions->create('zz', 'Zz own tongue', false);
        });
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::languages,
            (string) $created->id,
            [ObjectLanguage::code => 'zz'],
        ));
        Hilos::$browser->flushToSignalRouter();
        $live = $this->drain();

        self::assertTrue($this->namesRow($live, self::OPEN, 'zz'), $this->signalTrace($live));
        self::assertTrue($this->namesRow($live, self::SEARCH_HIT, 'zz'), $this->signalTrace($live));
        self::assertFalse($this->namesRow($live, self::SEARCH_MISS, 'zz'), $this->signalTrace($live));
    }

    public function testAViewerSeesEveryLanguageField(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Viewer language', true);
        });
        $viewer = Hilos::$db->users->actions->createWithName('Languages table viewer');
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
                $language = $row[PagePayload::slots][HilosI18nLanguagesTable::ROW_SLOT];
                foreach ([
                    HilosI18nLanguagesTableRow::code,
                    HilosI18nLanguagesTableRow::nativeName,
                    HilosI18nLanguagesTableRow::rtl,
                    HilosI18nLanguagesTableRow::enabled,
                    HilosI18nLanguagesTableRow::isDefault,
                    HilosI18nLanguagesTableRow::isOwn,
                ] as $field) {
                    self::assertFalse(HiddenValue::isMark($language[$field]), $field);
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
        $windows = $window === null ? [] : [ChatTableContext::hilosI18nLanguages => $window];
        Hilos::$sr->subscribeToPage(
            LanguagesListPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, LanguagesListPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey): void {
            new LanguagesListPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams([]));
        });
    }

    /**
     * @param list<HilosI18nLanguagesTableRow> $rows Window rows
     * @return list<string> Language codes in window order
     */
    private function codes(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            self::assertInstanceOf(HilosI18nLanguagesTableRow::class, $row);
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
     * @param list<HilosI18nLanguagesTableRow> $rows Window rows
     */
    private function rowByCode(array $rows, string $code): HilosI18nLanguagesTableRow
    {
        foreach ($rows as $row) {
            self::assertInstanceOf(HilosI18nLanguagesTableRow::class, $row);
            if ($row->code === $code) {
                return $row;
            }
        }

        self::fail("Language {$code} is not in the window");
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
     * @return array<string, mixed> Languages window of that connection
     */
    private function windowOf(array $signals, string $acceptKey): array
    {
        foreach ($signals as $signal) {
            if ($signal['accept'] !== $acceptKey || $signal['name'] !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(PageResponseSignalData::class, $signal['data']);
            $payload = $signal['data']->payload->toArray();
            if (!isset($payload[PagePayload::windows][ChatTableContext::hilosI18nLanguages])) {
                continue;
            }

            return $payload[PagePayload::windows][ChatTableContext::hilosI18nLanguages];
        }

        self::fail('The languages window was not delivered');
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'zz'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('en', 'qx', 'zz')");
        Hilos::$db->languageNames->getObjectCollection()?->reHydrate();
        Hilos::$db->languageNames->clearCache();
        Hilos::$db->locales->getObjectCollection()?->reHydrate();
        Hilos::$db->locales->clearCache();
        Hilos::$db->languages->getObjectCollection()?->reHydrate();
        Hilos::$db->languages->clearCache();
    }
}
