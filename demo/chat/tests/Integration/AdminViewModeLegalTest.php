<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Chat\Pages\Hilos\Legal\LegalPage;
use Demo\Chat\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Chat\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalSettings;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentPage;
use Hilos\Pages\Legal\AbstractHilosLegalPage;
use Hilos\Pages\Legal\AbstractHilosLegalRevisionPage;
use Hilos\Legal\Export\LegalAcceptancesExportProjector;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;
use Hilos\Tables\Legal\HilosLegalCheckTableRow;
use Hilos\Tables\Legal\HilosLegalDocumentTableRow;
use Hilos\Tables\Legal\HilosLegalRevisionTableRow;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use JsonException;

/**
 * Integration coverage for legal admin surfaces under the admin view mode (HIL-1258).
 *
 * Verifies that on the legal admin pages (LegalPage, LegalDocumentPage, LegalRevisionPage,
 * LegalAcceptancesPage, LegalSettingsPage) catalog definitions, checks, revisions and counts
 * are preserved for both anonymous and signed-in non-admin viewers, personal fields (name, email)
 * and setting values and defaults are hidden, and an admin receives all pages with no hidden marks.
 */
final class AdminViewModeLegalTest extends IntegrationTestCase
{
    private const string ANONYMOUS_KEY = 'admin-view-mode-legal-anonymous';
    private const string VISITOR_KEY = 'admin-view-mode-legal-visitor';
    private const string ADMIN_KEY = 'admin-view-mode-legal-admin';
    private const string TEST_AGENT = 'admin-view-mode-legal-test';

    private const string TERMS_DOCUMENT = LegalDocument::TERMS->value;
    private const string TEST_REVISION_ID = '2026-10-01';
    private const string TEST_TIMESTAMP = '2026-10-01 12:00:00';
    private const string TEST_USER_NAME = 'Olena Kovalenko';
    private const string TEST_USER_EMAIL = 'olena.kovalenko@example.com';
    private const string TEST_PASSWORD = 'a long enough passphrase';

    private int $personId;

    private int $acceptanceId;

    /** @var list<int> */
    private array $createdUserIds = [];

    /**
     * Initializes the chat browser context, registers truth sources, enables the view mode, and seeds data.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        Database::sqlRun('DELETE FROM hilos_legal_acceptance');
        Database::sqlRun('DELETE FROM hilos_identity WHERE identifier = ?', [self::TEST_USER_EMAIL]);
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new AdminViewModeLegalRouter();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $person = Hilos::$db->users->actions->createWithName(self::TEST_USER_NAME);
        $this->personId = (int) $person->id;
        Hilos::$db->identities->createPasswordIdentity($this->personId, self::TEST_USER_EMAIL, self::TEST_PASSWORD)->markVerified();
        $this->acceptanceId = $this->record($this->personId, self::TEST_REVISION_ID, self::TEST_TIMESTAMP);

        $visitor = Hilos::$db->users->actions->createWithName('Visitor of the node');
        $visitorId = (int) $visitor->id;

        $admin = Hilos::$db->users->actions->createWithName('Admin of the node');
        $admin->actions->setAdmin(true);
        $adminId = (int) $admin->id;

        $this->createdUserIds = [$this->personId, $visitorId, $adminId];

        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
    }

    /**
     * Disables the view mode, clears acceptances and runtime connections, and resets router and truth-source claims.
     */
    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        Database::sqlRun('DELETE FROM hilos_legal_acceptance');
        Database::sqlRun('DELETE FROM hilos_identity WHERE identifier = ?', [self::TEST_USER_EMAIL]);
        if ($this->createdUserIds !== []) {
            Database::sqlRun('DELETE FROM hilos_identity WHERE user_id IN (' . implode(',', $this->createdUserIds) . ')');
            Database::sqlRun('DELETE FROM hilos_session WHERE user_id IN (' . implode(',', $this->createdUserIds) . ')');
            Database::sqlRun('DELETE FROM hilos_user WHERE id IN (' . implode(',', $this->createdUserIds) . ')');
            $this->createdUserIds = [];
        }
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    /**
     * A viewer receives the root legal page with unmasked declarations, counts and checks, and masked setting values.
     */
    public function testAViewerSeesTheRootLegalPageWithUnmaskedDeclarationsAndMaskedSettingValues(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                LegalPage::class,
                [],
                $acceptKey,
                [
                    ChatTableContext::hilosLegalDocuments => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                    ChatTableContext::hilosLegalChecks => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                    ChatTableContext::hilosLegalSettings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ],
            );
            $pageData = $this->pagePayload($frames)[PagePayload::data];
            self::assertNull($pageData[AbstractHilosLegalPage::CATALOG_REFUSAL]);
            self::assertFalse(HiddenValue::isMark($pageData[AbstractHilosLegalPage::CATALOG_REFUSAL]));

            $docRows = $this->window($frames, ChatTableContext::hilosLegalDocuments)[TableWindowSignalData::rows];
            $termsRow = null;
            foreach ($docRows as $row) {
                if ($row[BrowserPageSignalData::rowKey] === self::TERMS_DOCUMENT) {
                    $termsRow = $row;
                    break;
                }
            }
            self::assertNotNull($termsRow, "terms document row was not found for {$acceptKey}");
            self::assertCount(1, $termsRow[PagePayload::slots]);
            $docSlot = reset($termsRow[PagePayload::slots]);
            foreach ($docSlot as $val) {
                self::assertFalse(HiddenValue::isMark($val));
            }
            self::assertSame(1, $docSlot[HilosLegalDocumentTableRow::covered]);

            $checkRows = $this->window($frames, ChatTableContext::hilosLegalChecks)[TableWindowSignalData::rows];
            self::assertCount(4, $checkRows);
            foreach ($checkRows as $checkRow) {
                self::assertCount(1, $checkRow[PagePayload::slots]);
                $checkSlot = reset($checkRow[PagePayload::slots]);
                self::assertFalse(HiddenValue::isMark($checkSlot[HilosLegalCheckTableRow::ok]));
                self::assertFalse(HiddenValue::isMark($checkSlot[HilosLegalCheckTableRow::items]));
            }

            $settingRows = $this->window($frames, ChatTableContext::hilosLegalSettings)[TableWindowSignalData::rows];
            self::assertCount(2, $settingRows);
            foreach ($settingRows as $settingRow) {
                $key = $settingRow[BrowserPageSignalData::rowKey];
                self::assertContains($key, LegalSettings::KEYS);
                self::assertCount(1, $settingRow[PagePayload::slots]);
                $settingSlot = reset($settingRow[PagePayload::slots]);
                self::assertSame($key, $settingSlot[HilosLegalSettingsTableRow::rowKey]);
                self::assertTrue(HiddenValue::isMark($settingSlot[HilosLegalSettingsTableRow::value]));
                self::assertTrue(HiddenValue::isMark($settingSlot[HilosLegalSettingsTableRow::defaultValue]));
            }
        }
    }

    /**
     * A viewer receives the document page with code catalog declarations, deviations, and unmasked revisions.
     */
    public function testAViewerSeesTheLegalDocumentPageWithCatalogDeclarationsAndRevisions(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                LegalDocumentPage::class,
                [HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY => self::TERMS_DOCUMENT],
                $acceptKey,
                [
                    ChatTableContext::hilosLegalRevisions => new TableWindowDescriptorDTO(
                        limit: TableConstants::NO_LIMIT,
                        filter: [HilosLegalRevisionsTable::FILTER_DOCUMENT => self::TERMS_DOCUMENT],
                    ),
                ],
            );
            $pageData = $this->pagePayload($frames)[PagePayload::data];
            $document = $pageData[AbstractHilosLegalDocumentPage::SECTION];
            self::assertFalse(HiddenValue::isMark($document));
            self::assertSame(1, $document['set']['version']);
            self::assertCount(4, $document['deviations']);

            $revRows = $this->window($frames, ChatTableContext::hilosLegalRevisions)[TableWindowSignalData::rows];
            self::assertNotEmpty($revRows);
            foreach ($revRows as $revRow) {
                self::assertCount(1, $revRow[PagePayload::slots]);
                $revSlot = reset($revRow[PagePayload::slots]);
                foreach ($revSlot as $val) {
                    self::assertFalse(HiddenValue::isMark($val));
                }
            }
        }
    }

    /**
     * A viewer receives the revision page with unmasked catalog clauses and changes.
     */
    public function testAViewerSeesTheLegalRevisionPageWithClausesAndChanges(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                LegalRevisionPage::class,
                [
                    HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY => self::TERMS_DOCUMENT,
                    HilosPageRouteParams::HILOS_LEGAL_REVISION_ID => self::TEST_REVISION_ID,
                ],
                $acceptKey,
            );
            $pageData = $this->pagePayload($frames)[PagePayload::data];
            $revision = $pageData[AbstractHilosLegalRevisionPage::SECTION];
            self::assertFalse(HiddenValue::isMark($revision));
            self::assertCount(6, $revision['clauses']);
            self::assertCount(1, $revision['changes']);
        }
    }

    /**
     * A viewer receives legal acceptances with filter vocabulary, unmasked acceptance fields, and masked name and email.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAViewerSeesLegalAcceptancesWithFilterVocabularyAndMaskedPersonFields(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                LegalAcceptancesPage::class,
                [],
                $acceptKey,
                [
                    ChatTableContext::hilosLegalAcceptances => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ],
            );
            $filtersAt = array_search(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES, array_column($frames, 'name'), true);
            self::assertIsInt($filtersAt);
            self::assertNotInstanceOf(HilosLegalAcceptanceFiltersSignalData::class, $frames[$filtersAt]['data']);
            $filterData = $frames[$filtersAt]['data']->toArray();
            $documents = array_column(
                $filterData[HilosLegalAcceptanceFiltersSignalData::documents],
                null,
                HilosLegalAcceptanceFiltersSignalData::document,
            );
            self::assertArrayHasKey(self::TERMS_DOCUMENT, $documents);
            $terms = $documents[self::TERMS_DOCUMENT];
            self::assertFalse(HiddenValue::isMark($terms[HilosLegalAcceptanceFiltersSignalData::declared]));
            $revisions = $terms[HilosLegalAcceptanceFiltersSignalData::revisions];
            $revisionIds = array_column($revisions, HilosLegalAcceptanceFiltersSignalData::revisionId);
            self::assertContains(self::TEST_REVISION_ID, $revisionIds);
            foreach ($revisions as $rev) {
                self::assertFalse(HiddenValue::isMark($rev[HilosLegalAcceptanceFiltersSignalData::revisionId]));
            }

            $accRows = $this->window($frames, ChatTableContext::hilosLegalAcceptances)[TableWindowSignalData::rows];
            self::assertCount(1, $accRows);
            $accRow = $accRows[0];
            self::assertCount(1, $accRow[PagePayload::slots]);
            $acc = reset($accRow[PagePayload::slots]);
            self::assertFalse(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::rowKey]));
            self::assertFalse(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::userId]));
            self::assertFalse(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::document]));
            self::assertFalse(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::revisionId]));
            self::assertFalse(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::acceptedAt]));
            self::assertFalse(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::declared]));
            self::assertSame($this->acceptanceId, $acc[HilosLegalAcceptanceTableRow::rowKey]);
            self::assertSame($this->personId, $acc[HilosLegalAcceptanceTableRow::userId]);
            self::assertSame(self::TERMS_DOCUMENT, $acc[HilosLegalAcceptanceTableRow::document]);
            self::assertSame(self::TEST_REVISION_ID, $acc[HilosLegalAcceptanceTableRow::revisionId]);
            self::assertSame(self::TEST_TIMESTAMP, $acc[HilosLegalAcceptanceTableRow::acceptedAt]);
            self::assertTrue($acc[HilosLegalAcceptanceTableRow::declared]);
            self::assertTrue(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::name]));
            self::assertTrue(HiddenValue::isMark($acc[HilosLegalAcceptanceTableRow::email]));
            $everyFrame = json_encode(
                array_map(static fn(array $frame): array => $frame['data']->toArray(), $frames),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringNotContainsString(self::TEST_USER_NAME, $everyFrame);
            self::assertStringNotContainsString(self::TEST_USER_EMAIL, $everyFrame);
            foreach (self::payloads($frames) as $payload) {
                self::assertArrayNotHasKey(
                    LegalAcceptancesExportProjector::SECTION,
                    $payload[PageResponseSignalData::payload][PagePayload::data] ?? [],
                    'A viewer has no export of acceptance records to see (HIL-1234)',
                );
            }
        }
    }

    /**
     * A viewer receives legal settings with setting keys preserved and both values and defaults masked.
     */
    public function testAViewerSeesLegalSettingsWithSettingKeysAndMaskedValuesAndDefaults(): void
    {
        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(
                LegalSettingsPage::class,
                [],
                $acceptKey,
                [
                    ChatTableContext::hilosLegalSettings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ],
            );
            $settingRows = $this->window($frames, ChatTableContext::hilosLegalSettings)[TableWindowSignalData::rows];
            self::assertCount(2, $settingRows);
            foreach ($settingRows as $settingRow) {
                $key = $settingRow[BrowserPageSignalData::rowKey];
                self::assertContains($key, LegalSettings::KEYS);
                self::assertCount(1, $settingRow[PagePayload::slots]);
                $settingSlot = reset($settingRow[PagePayload::slots]);
                self::assertSame($key, $settingSlot[HilosLegalSettingsTableRow::rowKey]);
                self::assertTrue(HiddenValue::isMark($settingSlot[HilosLegalSettingsTableRow::value]));
                self::assertTrue(HiddenValue::isMark($settingSlot[HilosLegalSettingsTableRow::defaultValue]));
            }
        }
    }

    /**
     * An admin receives all five legal pages with real data and no hidden marks.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAnAdminIsSentThePagesWithoutASingleMark(): void
    {
        $frames = [
            ...$this->subscribe(LegalPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosLegalDocuments => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ChatTableContext::hilosLegalChecks => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
                ChatTableContext::hilosLegalSettings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(
                LegalDocumentPage::class,
                [HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY => self::TERMS_DOCUMENT],
                self::ADMIN_KEY,
                [
                    ChatTableContext::hilosLegalRevisions => new TableWindowDescriptorDTO(
                        limit: TableConstants::NO_LIMIT,
                        filter: [HilosLegalRevisionsTable::FILTER_DOCUMENT => self::TERMS_DOCUMENT],
                    ),
                ],
            ),
            ...$this->subscribe(
                LegalRevisionPage::class,
                [
                    HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY => self::TERMS_DOCUMENT,
                    HilosPageRouteParams::HILOS_LEGAL_REVISION_ID => self::TEST_REVISION_ID,
                ],
                self::ADMIN_KEY,
            ),
            ...$this->subscribe(LegalAcceptancesPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosLegalAcceptances => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(LegalSettingsPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosLegalSettings => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
        ];

        $accRows = $this->window($frames, ChatTableContext::hilosLegalAcceptances)[TableWindowSignalData::rows];
        self::assertCount(1, $accRows);
        $accSlot = reset($accRows[0][PagePayload::slots]);
        self::assertSame(self::TEST_USER_NAME, $accSlot[HilosLegalAcceptanceTableRow::name]);
        self::assertSame(self::TEST_USER_EMAIL, $accSlot[HilosLegalAcceptanceTableRow::email]);

        $settingRows = $this->window($frames, ChatTableContext::hilosLegalSettings)[TableWindowSignalData::rows];
        $consentSetting = null;
        foreach ($settingRows as $settingRow) {
            if ($settingRow[BrowserPageSignalData::rowKey] === LegalSettings::CONSENT_FORM_KEY) {
                $consentSetting = reset($settingRow[PagePayload::slots]);
                break;
            }
        }
        self::assertNotNull($consentSetting, 'legal.consent_form row was not found');
        self::assertSame(LegalSettings::CONSENT_FORM_CHECKBOX, $consentSetting[HilosLegalSettingsTableRow::defaultValue]);

        self::assertStringNotContainsString(HiddenValue::KEY, json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    /**
     * @param int $userId Person giving the acceptance
     * @param string $revisionId Exact recorded revision
     * @param string $acceptedAt SQL acceptance timestamp
     * @return int Id of the recorded acceptance
     */
    private function record(int $userId, string $revisionId, string $acceptedAt): int
    {
        Database::sql(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$userId, self::TERMS_DOCUMENT, $revisionId, $acceptedAt],
        );

        return Database::lastInsertId();
    }

    /**
     * Subscribes to a page on behalf of a connection and collects queued WebSocket frames.
     *
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param array<string, string> $params Route parameters
     * @param string $acceptKey Connection accept key
     * @param array<string, TableWindowDescriptorDTO> $tableWindows Windows the tab holds, by table key
     * @return list<array{name: string, data: SignalDataInterface}> Queued browser frames in delivery order
     */
    private function subscribe(
        string $pageClass,
        array $params = [],
        string $acceptKey = self::ANONYMOUS_KEY,
        array $tableWindows = [],
    ): array {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, $params, $tableWindows));
        if ($tableWindows !== []) {
            Hilos::$sr->reportTableWindows($acceptKey, $tableWindows);
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($pageClass, $params, $acceptKey): void {
            new $pageClass(new DemoHilosLegalAgent())->onSubscribe($acceptKey, new PageRouteParams($params));
        });
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }

        return $frames;
    }

    /**
     * Extracts the window payload of a viewport table from page response payloads.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @param string $tableKey Viewport table whose window is read
     * @return array<string, mixed> The window section of that table
     */
    private function window(array $frames, string $tableKey): array
    {
        foreach (self::payloads($frames) as $payload) {
            $window = $payload[PageResponseSignalData::payload][PagePayload::windows][$tableKey] ?? null;
            if (is_array($window)) {
                return $window;
            }
        }
        self::fail("No page answer carried the window of {$tableKey}");
    }

    /**
     * Extracts the single page payload carried by a page response frame.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return array<string, mixed> Page response payload
     */
    private function pagePayload(array $frames): array
    {
        foreach (self::payloads($frames) as $payload) {
            $pagePayload = $payload[PageResponseSignalData::payload] ?? null;
            if (is_array($pagePayload) && array_key_exists(PagePayload::data, $pagePayload)) {
                return $pagePayload;
            }
        }
        foreach (self::payloads($frames) as $payload) {
            $pagePayload = $payload[PageResponseSignalData::payload] ?? null;
            if (is_array($pagePayload)) {
                return $pagePayload;
            }
        }
        self::fail('No page response payload was found');
    }

    /**
     * Extracts wire payload arrays from page response frames.
     *
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return list<array<string, mixed>> Wire arrays of the page answers among them
     */
    private static function payloads(array $frames): array
    {
        return array_values(array_map(
            static fn(array $frame): array => $frame['data']->toArray(),
            array_filter($frames, static fn(array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE),
        ));
    }
}

/**
 * Router answering from the chat demo's topology rather than the framework's bare one.
 */
final class AdminViewModeLegalRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
