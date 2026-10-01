<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Backup\BackupPage;
use Demo\Chat\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\Chat\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Backup\BackupMetadata;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupShipOutcome;
use Hilos\Backup\BackupStatus;
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
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Pages\Backup\AbstractHilosBackupPage;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Backup\HilosBackupTableRow;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use JsonException;

/**
 * Integration coverage for operations admin surfaces under the admin view mode (HIL-1256).
 *
 * Verifies that on the verifier circle, channel deliveries, and backup history surfaces
 * non-personal fields and status indicators are preserved for anonymous and non-admin viewers,
 * personal fields and failure texts are hidden, and an administrator receives complete frames with
 * no hidden marks.
 */
final class AdminViewModeOperationsTest extends IntegrationTestCase
{
    private const string ANONYMOUS_KEY = 'admin-view-mode-ops-anonymous';
    private const string VISITOR_KEY = 'admin-view-mode-ops-visitor';
    private const string ADMIN_KEY = 'admin-view-mode-ops-admin';
    private const string PERSON_KEY = 'admin-view-mode-ops-person';
    private const string TEST_AGENT = 'admin-view-mode-ops-test';

    private const string TEST_USER_NAME = 'Olena Kovalenko';
    private const string TEST_USER_EMAIL = 'olena.kovalenko@example.com';
    private const string TEST_PASSWORD = 'a long enough passphrase';
    private const string ARCHIVE_A_ID = 'a';
    private const string ARCHIVE_B_ID = 'b';

    private int $personId;
    private int $memberId;
    private ?int $notificationId = null;
    private ?int $deliveryId = null;

    /** @var list<int> */
    private array $createdUserIds = [];

    /**
     * Initializes the chat browser context, registers truth sources, enables the view mode, and seeds test data.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Database::sqlRun('DELETE FROM hilos_verifier_circle WHERE identifier = ?', [self::TEST_USER_EMAIL]);
        Database::sqlRun('DELETE FROM hilos_identity WHERE identifier = ?', [self::TEST_USER_EMAIL]);
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new AdminViewModeOperationsRouter();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $person = Hilos::$db->users->actions->createWithName(self::TEST_USER_NAME);
        $this->personId = (int) $person->id;
        $identity = Hilos::$db->identities->createPasswordIdentity($this->personId, self::TEST_USER_EMAIL, self::TEST_PASSWORD);
        $identity->markVerified();

        $visitor = Hilos::$db->users->actions->createWithName('Visitor of the node');
        $visitorId = (int) $visitor->id;

        $admin = Hilos::$db->users->actions->createWithName('Admin of the node');
        $admin->actions->setAdmin(true);
        $adminId = (int) $admin->id;

        $this->createdUserIds = [$this->personId, $visitorId, $adminId];

        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        Hilos::$rt->connections->actions->register(self::PERSON_KEY, $this->personId);

        TruthSourceRegistry::register(HilosDbContext::verifierCircle, TruthSourceKeys::all(), self::TEST_AGENT);
        $member = Hilos::$db->verifierCircle->actions->add($identity->type, self::TEST_USER_EMAIL);
        $this->memberId = (int) $member->id;

        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `severity`, `title`, `body`) VALUES (?, ?, ?, ?, ?)',
            [$this->personId, 'notice', 'info', 'Notice for Olena Kovalenko <' . self::TEST_USER_EMAIL . '>', 'Body'],
        );
        $this->notificationId = (int) Database::sql('SELECT MAX(`id`) AS id FROM `hilos_notification`')->firstRow()['id'];

        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `status`, `attempts`, `last_error`, `created_at`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [
                $this->notificationId,
                'email',
                'failed',
                3,
                'Delivery to ' . self::TEST_USER_EMAIL . ' failed: mailbox unavailable',
                '2026-10-01 12:00:00',
            ],
        );
        $this->deliveryId = (int) Database::sql('SELECT MAX(`id`) AS id FROM `hilos_notification_delivery`')->firstRow()['id'];

        RtTruthSourceRegistry::registerDaemon(BackupHistory::RT_COLLECTION);
        $archiveA = new BackupMetadata(
            id: self::ARCHIVE_A_ID,
            createdAt: '2026-09-16T00:00:00+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 0,
            keep: false,
            status: BackupStatus::ERROR,
            failureReason: 'pg_dump failed: ' . self::TEST_USER_EMAIL,
        );
        $archiveB = new BackupMetadata(
            id: self::ARCHIVE_B_ID,
            createdAt: '2026-09-16T01:00:00+00:00',
            env: 'test',
            scope: BackupScope::FULL,
            connections: [],
            sizeBytes: 0,
            durationSeconds: 0,
            keep: false,
            status: BackupStatus::SUCCESS,
            shipOutcome: BackupShipOutcome::FAILED,
            shipError: 'ssh: connect to backup.example.com timed out',
        );
        Hilos::$rt->hilosBackupHistories->actions->syncToScan([$archiveA, $archiveB], 'node-1');
    }

    /**
     * Disables the view mode, clears seeded records, and resets router and truth-source claims.
     */
    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::initBrowser();

        Hilos::$rt->hilosBackupHistories->actions->forget(self::ARCHIVE_A_ID);
        Hilos::$rt->hilosBackupHistories->actions->forget(self::ARCHIVE_B_ID);
        RtTruthSourceRegistry::unregisterDaemon(BackupHistory::RT_COLLECTION);

        if ($this->deliveryId !== null) {
            Database::sqlRun('DELETE FROM hilos_notification_delivery WHERE id = ?', [$this->deliveryId]);
            $this->deliveryId = null;
        }
        if ($this->notificationId !== null) {
            Database::sqlRun('DELETE FROM hilos_notification WHERE id = ?', [$this->notificationId]);
            $this->notificationId = null;
        }

        Database::sqlRun('DELETE FROM hilos_verifier_circle WHERE identifier = ?', [self::TEST_USER_EMAIL]);
        TruthSourceRegistry::unregister(HilosDbContext::verifierCircle, self::TEST_AGENT);
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
     * A viewer sees the verifier circle with hidden address and unmasked presence equal to an admin's.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAViewerSeesTheVerifierCircleWithHiddenIdentifierAndUnmaskedPresence(): void
    {
        $adminFrames = $this->subscribe(MaintenancePage::class, [], self::ADMIN_KEY, [
            ChatTableContext::hilosVerifierCircle => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
        ]);
        $adminRows = $this->window($adminFrames, ChatTableContext::hilosVerifierCircle)[TableWindowSignalData::rows];
        $adminMember = null;
        foreach ($adminRows as $row) {
            if ($row[BrowserPageSignalData::rowKey] === $this->memberId) {
                self::assertCount(1, $row[PagePayload::slots]);
                $adminMember = reset($row[PagePayload::slots]);
                break;
            }
        }
        self::assertNotNull($adminMember);

        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(MaintenancePage::class, [], $acceptKey, [
                ChatTableContext::hilosVerifierCircle => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $rows = $this->window($frames, ChatTableContext::hilosVerifierCircle)[TableWindowSignalData::rows];
            $memberRow = null;
            foreach ($rows as $row) {
                if ($row[BrowserPageSignalData::rowKey] === $this->memberId) {
                    $memberRow = $row;
                    break;
                }
            }
            self::assertNotNull($memberRow, "Verifier circle member was not found for {$acceptKey}");
            self::assertSame($this->memberId, $memberRow[BrowserPageSignalData::rowKey]);
            self::assertCount(1, $memberRow[PagePayload::slots]);
            $slot = reset($memberRow[PagePayload::slots]);

            self::assertTrue(HiddenValue::isMark($slot[HilosVerifierCircleTableRow::identifier]));
            self::assertSame($adminMember[HilosVerifierCircleTableRow::identityType], $slot[HilosVerifierCircleTableRow::identityType]);
            self::assertSame($adminMember[HilosVerifierCircleTableRow::online], $slot[HilosVerifierCircleTableRow::online]);
            self::assertTrue($slot[HilosVerifierCircleTableRow::online]);

            $json = json_encode(self::payloads($frames), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TEST_USER_EMAIL, $json);
            self::assertStringNotContainsString(self::TEST_USER_NAME, $json);
        }
    }

    /**
     * A viewer sees channel deliveries with hidden recipient details, title, and error message.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAViewerSeesCommunicationsDeliveriesWithHiddenTitleErrorAndRecipient(): void
    {
        $adminFrames = $this->subscribe(CommunicationsDeliveriesPage::class, [], self::ADMIN_KEY, [
            ChatTableContext::hilosNotificationDeliveries => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
        ]);
        $adminRows = $this->window($adminFrames, ChatTableContext::hilosNotificationDeliveries)[TableWindowSignalData::rows];
        $adminDelivery = null;
        foreach ($adminRows as $row) {
            if ($row[BrowserPageSignalData::rowKey] === $this->deliveryId) {
                self::assertCount(1, $row[PagePayload::slots]);
                $adminDelivery = reset($row[PagePayload::slots]);
                break;
            }
        }
        self::assertNotNull($adminDelivery);

        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(CommunicationsDeliveriesPage::class, [], $acceptKey, [
                ChatTableContext::hilosNotificationDeliveries => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $rows = $this->window($frames, ChatTableContext::hilosNotificationDeliveries)[TableWindowSignalData::rows];
            $deliveryRow = null;
            foreach ($rows as $row) {
                if ($row[BrowserPageSignalData::rowKey] === $this->deliveryId) {
                    $deliveryRow = $row;
                    break;
                }
            }
            self::assertNotNull($deliveryRow, "Delivery row was not found for {$acceptKey}");
            self::assertSame($this->deliveryId, $deliveryRow[BrowserPageSignalData::rowKey]);
            self::assertCount(1, $deliveryRow[PagePayload::slots]);
            $slot = reset($deliveryRow[PagePayload::slots]);

            self::assertTrue(HiddenValue::isMark($slot[HilosNotificationDeliveryTableRow::lastError]));
            self::assertTrue(HiddenValue::isMark($slot[HilosNotificationDeliveryTableRow::notificationTitle]));
            self::assertTrue(HiddenValue::isMark($slot[HilosNotificationDeliveryTableRow::userLabel]));

            self::assertSame($adminDelivery[HilosNotificationDeliveryTableRow::createdAt], $slot[HilosNotificationDeliveryTableRow::createdAt]);
            self::assertSame($adminDelivery[HilosNotificationDeliveryTableRow::channel], $slot[HilosNotificationDeliveryTableRow::channel]);
            self::assertSame($adminDelivery[HilosNotificationDeliveryTableRow::status], $slot[HilosNotificationDeliveryTableRow::status]);
            self::assertSame($adminDelivery[HilosNotificationDeliveryTableRow::attempts], $slot[HilosNotificationDeliveryTableRow::attempts]);
            self::assertSame($adminDelivery[HilosNotificationDeliveryTableRow::deliveredAt], $slot[HilosNotificationDeliveryTableRow::deliveredAt]);
            self::assertSame($adminDelivery[HilosNotificationDeliveryTableRow::userId], $slot[HilosNotificationDeliveryTableRow::userId]);
            self::assertSame($this->personId, $slot[HilosNotificationDeliveryTableRow::userId]);
            self::assertSame(
                $adminDelivery[HilosNotificationDeliveryTableRow::notificationType],
                $slot[HilosNotificationDeliveryTableRow::notificationType],
            );

            $json = json_encode(self::payloads($frames), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TEST_USER_EMAIL, $json);
            self::assertStringNotContainsString(self::TEST_USER_NAME, $json);
            self::assertStringNotContainsString('mailbox unavailable', $json);
        }
    }

    /**
     * A viewer sees the backup history with hidden refusal texts, unmasked status, and unmasked page data.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAViewerSeesBackupHistoryWithHiddenRefusalTextsAndUnmaskedPageSections(): void
    {
        $adminFrames = $this->subscribe(BackupPage::class, [], self::ADMIN_KEY, [
            ChatTableContext::hilosBackups => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
        ]);
        $adminPageData = $this->pagePayload($adminFrames)[PagePayload::data];
        $adminRows = $this->window($adminFrames, ChatTableContext::hilosBackups)[TableWindowSignalData::rows];
        $adminSlotA = null;
        $adminSlotB = null;
        foreach ($adminRows as $row) {
            if ($row[BrowserPageSignalData::rowKey] === self::ARCHIVE_A_ID) {
                self::assertCount(1, $row[PagePayload::slots]);
                $adminSlotA = reset($row[PagePayload::slots]);
            } elseif ($row[BrowserPageSignalData::rowKey] === self::ARCHIVE_B_ID) {
                self::assertCount(1, $row[PagePayload::slots]);
                $adminSlotB = reset($row[PagePayload::slots]);
            }
        }
        self::assertNotNull($adminSlotA);
        self::assertNotNull($adminSlotB);

        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
            $frames = $this->subscribe(BackupPage::class, [], $acceptKey, [
                ChatTableContext::hilosBackups => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]);
            $pageData = $this->pagePayload($frames)[PagePayload::data];

            self::assertFalse(HiddenValue::isMark($pageData[AbstractHilosBackupPage::RESTORE_SECTION]));
            self::assertFalse(HiddenValue::isMark($pageData[AbstractHilosBackupPage::RESTORE_SECTION][AbstractHilosBackupPage::RESTORE_UI_ENABLED]));
            self::assertFalse(HiddenValue::isMark($pageData[AbstractHilosBackupPage::RESTORE_SECTION][AbstractHilosBackupPage::RESTORE_TARGET_ENV]));
            self::assertSame(
                $adminPageData[AbstractHilosBackupPage::RESTORE_SECTION][AbstractHilosBackupPage::RESTORE_UI_ENABLED],
                $pageData[AbstractHilosBackupPage::RESTORE_SECTION][AbstractHilosBackupPage::RESTORE_UI_ENABLED],
            );
            self::assertSame(
                $adminPageData[AbstractHilosBackupPage::RESTORE_SECTION][AbstractHilosBackupPage::RESTORE_TARGET_ENV],
                $pageData[AbstractHilosBackupPage::RESTORE_SECTION][AbstractHilosBackupPage::RESTORE_TARGET_ENV],
            );

            self::assertFalse(HiddenValue::isMark($pageData[AbstractHilosBackupPage::REOPEN_SECTION]));
            self::assertFalse(HiddenValue::isMark($pageData[AbstractHilosBackupPage::REOPEN_SECTION][AbstractHilosBackupPage::REOPEN_OFFERED]));
            self::assertSame(
                $adminPageData[AbstractHilosBackupPage::REOPEN_SECTION][AbstractHilosBackupPage::REOPEN_OFFERED],
                $pageData[AbstractHilosBackupPage::REOPEN_SECTION][AbstractHilosBackupPage::REOPEN_OFFERED],
            );

            $rows = $this->window($frames, ChatTableContext::hilosBackups)[TableWindowSignalData::rows];
            $slotA = null;
            $slotB = null;
            foreach ($rows as $row) {
                if ($row[BrowserPageSignalData::rowKey] === self::ARCHIVE_A_ID) {
                    self::assertCount(1, $row[PagePayload::slots]);
                    $slotA = reset($row[PagePayload::slots]);
                } elseif ($row[BrowserPageSignalData::rowKey] === self::ARCHIVE_B_ID) {
                    self::assertCount(1, $row[PagePayload::slots]);
                    $slotB = reset($row[PagePayload::slots]);
                }
            }
            self::assertNotNull($slotA);
            self::assertNotNull($slotB);

            $refusalFields = [
                HilosBackupTableRow::failureReason => true,
                HilosBackupTableRow::shipError => true,
                HilosBackupTableRow::restoreFailureReason => true,
            ];

            // Archive A: refusal texts are marked; finished is null; others match admin
            self::assertTrue(HiddenValue::isMark($slotA[HilosBackupTableRow::failureReason]));
            self::assertTrue(HiddenValue::isMark($slotA[HilosBackupTableRow::shipError]));
            self::assertTrue(HiddenValue::isMark($slotA[HilosBackupTableRow::restoreFailureReason]));
            self::assertNull($slotA[HilosBackupTableRow::finished]);
            self::assertFalse(HiddenValue::isMark($slotA[HilosBackupTableRow::finished]));
            self::assertFalse(HiddenValue::isMark($slotA[HilosBackupTableRow::restoreMigrationNotice]));

            $diffA = array_diff_key($slotA, $refusalFields);
            $adminDiffA = array_diff_key($adminSlotA, $refusalFields);
            self::assertSame($adminDiffA, $diffA);

            // Archive B: refusal texts are marked; others match admin
            self::assertTrue(HiddenValue::isMark($slotB[HilosBackupTableRow::failureReason]));
            self::assertTrue(HiddenValue::isMark($slotB[HilosBackupTableRow::shipError]));
            self::assertTrue(HiddenValue::isMark($slotB[HilosBackupTableRow::restoreFailureReason]));
            self::assertFalse(HiddenValue::isMark($slotB[HilosBackupTableRow::restoreMigrationNotice]));

            $diffB = array_diff_key($slotB, $refusalFields);
            $adminDiffB = array_diff_key($adminSlotB, $refusalFields);
            self::assertSame($adminDiffB, $diffB);

            $json = json_encode(self::payloads($frames), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TEST_USER_EMAIL, $json);
            self::assertStringNotContainsString('pg_dump failed', $json);
            self::assertStringNotContainsString('ssh: connect to backup.example.com timed out', $json);
        }
    }

    /**
     * An administrator receives all three operations pages with real values and without hidden marks.
     *
     * @throws JsonException When JSON serialization fails
     */
    public function testAnAdminIsSentThePagesWithoutASingleMark(): void
    {
        $frames = [
            ...$this->subscribe(MaintenancePage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosVerifierCircle => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(CommunicationsDeliveriesPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosNotificationDeliveries => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
            ...$this->subscribe(BackupPage::class, [], self::ADMIN_KEY, [
                ChatTableContext::hilosBackups => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT),
            ]),
        ];

        $circleRows = $this->window($frames, ChatTableContext::hilosVerifierCircle)[TableWindowSignalData::rows];
        $circleMember = null;
        foreach ($circleRows as $row) {
            if ($row[BrowserPageSignalData::rowKey] === $this->memberId) {
                self::assertCount(1, $row[PagePayload::slots]);
                $circleMember = reset($row[PagePayload::slots]);
                break;
            }
        }
        self::assertNotNull($circleMember);
        self::assertSame(self::TEST_USER_EMAIL, $circleMember[HilosVerifierCircleTableRow::identifier]);

        $deliveryRows = $this->window($frames, ChatTableContext::hilosNotificationDeliveries)[TableWindowSignalData::rows];
        $deliverySlot = null;
        foreach ($deliveryRows as $row) {
            if ($row[BrowserPageSignalData::rowKey] === $this->deliveryId) {
                self::assertCount(1, $row[PagePayload::slots]);
                $deliverySlot = reset($row[PagePayload::slots]);
                break;
            }
        }
        self::assertNotNull($deliverySlot);
        self::assertSame(
            'Notice for Olena Kovalenko <' . self::TEST_USER_EMAIL . '>',
            $deliverySlot[HilosNotificationDeliveryTableRow::notificationTitle],
        );
        self::assertSame(
            'Delivery to ' . self::TEST_USER_EMAIL . ' failed: mailbox unavailable',
            $deliverySlot[HilosNotificationDeliveryTableRow::lastError],
        );

        $backupRows = $this->window($frames, ChatTableContext::hilosBackups)[TableWindowSignalData::rows];
        $slotA = null;
        $slotB = null;
        foreach ($backupRows as $row) {
            if ($row[BrowserPageSignalData::rowKey] === self::ARCHIVE_A_ID) {
                self::assertCount(1, $row[PagePayload::slots]);
                $slotA = reset($row[PagePayload::slots]);
            } elseif ($row[BrowserPageSignalData::rowKey] === self::ARCHIVE_B_ID) {
                self::assertCount(1, $row[PagePayload::slots]);
                $slotB = reset($row[PagePayload::slots]);
            }
        }
        self::assertNotNull($slotA);
        self::assertNotNull($slotB);
        self::assertSame('pg_dump failed: ' . self::TEST_USER_EMAIL, $slotA[HilosBackupTableRow::failureReason]);
        self::assertSame('ssh: connect to backup.example.com timed out', $slotB[HilosBackupTableRow::shipError]);

        self::assertStringNotContainsString(HiddenValue::KEY, json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    /**
     * Opens a page under a WebSocket connection and drains the resulting queue of signals.
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
            new $pageClass(new DemoHilosAgent())->onSubscribe($acceptKey, new PageRouteParams($params));
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
final class AdminViewModeOperationsRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
