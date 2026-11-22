<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminVouchersPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Voucher\VouchersTable;
use Demo\EcommerceShop\Tables\Voucher\VoucherTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\DbSyncApplicator;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Mounts the shop's browser and its own signal router over the vouchers environment, turns the admin
 * view mode on, and claims the connections register the page gate reads a subscriber's identity from.
 *
 * The runtime writes run outside any agent, as the daemon's do: the environment beneath runs under the
 * vouchers library's frame, which owns neither the connections nor the view mode.
 */
abstract class AdminVouchersPageEnvironment extends VouchersLibraryEnvironment
{
    private const string TEST_AGENT = 'admin-vouchers-page-test';

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;

    /**
     * Starts the vouchers library beneath and forgets what the ORM loaded earlier in the process, then
     * mounts the browser, the router, the connections claim and the view mode.
     *
     * The people are rows written past the users library, so a users collection an earlier case warmed
     * would read them as absent; the next read reads the table again, inside the case, and the rollback
     * forgets that read with the rows.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetLoadedDataForTest();
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->claimed = true;
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        });
    }

    /**
     * Turns the view mode off and forgets the connections if they were claimed, restores the router and
     * the browser, then releases the library beneath, in every outcome of the preparation.
     */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->claimed) {
                $this->claimed = false;
                self::unattended(static function (): void {
                    Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
                    Hilos::$rt->connections->actions->clear();
                });
                RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
                RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
            }
            Hilos::$sr = $this->previousRouter;
            Hilos::initBrowser();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * Runs a runtime write outside any agent, the way the daemon writes.
     *
     * @param callable(): void $write The write
     */
    protected static function unattended(callable $write): void
    {
        ExecutionContext::run(new ExecutionFrame(), $write);
    }
}

/**
 * The vouchers admin page in the admin view mode: a guest, a signed-in person without admin rights and
 * an administrator see every field of a voucher in the first answer, and with the mode off the page is
 * closed to a person who is not an administrator.
 *
 * The vouchers are written through their library. The two people are rows written straight into their
 * table. A library edit of the discount reaches the guest's open window under the same row key.
 */
#[IntegrationTest]
final class AdminVouchersPageTest extends AdminVouchersPageEnvironment
{
    private const string GUEST_KEY = 'admin-vouchers-guest';
    private const string VISITOR_KEY = 'admin-vouchers-visitor';
    private const string ADMIN_KEY = 'admin-vouchers-admin';

    private int $activeId;
    private string $activeCode;
    private int $inactiveId;
    private string $inactiveCode;

    /**
     * Two vouchers of the case, one switched off, and a guest, a person without admin rights and an
     * administrator connected.
     */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $prefix = RandomHelper::hex(8);
        $active = Hilos::$db->vouchers->actions->create($prefix . 'on', 15, true);
        $inactive = Hilos::$db->vouchers->actions->create($prefix . 'off', 40, false);
        $activeId = $active->id;
        $inactiveId = $inactive->id;
        self::assertIsInt($activeId);
        self::assertIsInt($inactiveId);
        $this->activeId = $activeId;
        $this->activeCode = $active->code;
        $this->inactiveId = $inactiveId;
        $this->inactiveCode = $inactive->code;
        $visitorId = self::person('Vouchers visitor', false);
        $adminId = self::person('Vouchers admin', true);
        self::unattended(static function () use ($visitorId, $adminId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        });
    }

    /**
     * A guest, a signed-in person without admin rights and an administrator receive both vouchers with
     * every field shown, and the answer carries no list section.
     */
    public function testAdminAndViewersReceiveTheVoucherRowsWithoutMarks(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY, self::ADMIN_KEY] as $acceptKey) {
            $payloads = $this->subscribe($acceptKey);
            $payload = $payloads[0][PageResponseSignalData::payload];
            self::assertArrayNotHasKey(PagePayload::lists, $payload);
            self::assertArrayHasKey(EcommerceShopTableContext::vouchers, $payload[PagePayload::windows]);
            $rows = $payload[PagePayload::windows][EcommerceShopTableContext::vouchers][TableWindowSignalData::rows];
            $active = self::voucherSlot($rows, $this->activeId);
            $inactive = self::voucherSlot($rows, $this->inactiveId);
            self::assertSame([
                VoucherTableRow::id => $this->activeId,
                VoucherTableRow::code => $this->activeCode,
                VoucherTableRow::discountPercent => 15,
                VoucherTableRow::active => true,
            ], $active);
            self::assertSame([
                VoucherTableRow::id => $this->inactiveId,
                VoucherTableRow::code => $this->inactiveCode,
                VoucherTableRow::discountPercent => 40,
                VoucherTableRow::active => false,
            ], $inactive);
            foreach ([$active, $inactive] as $slot) {
                foreach ($slot as $field => $value) {
                    self::assertFalse(HiddenValue::isMark($value), "field {$field} is a mark for {$acceptKey}");
                }
            }
            if ($acceptKey === self::ADMIN_KEY) {
                self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payloads, JSON_THROW_ON_ERROR));
            }
        }
    }

    /** With the view mode off the page is closed to a person without admin rights. */
    public function testANonAdminIsRefusedWhenTheViewModeIsOff(): void
    {
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AdminVouchersPage::class, self::VISITOR_KEY);
    }

    /**
     * A library edit of the active voucher's percent reaches the guest's open window under the same row key.
     *
     * The vouchers table is a viewport, so the worker's fact redraws that one row in the open window: the
     * row keeps its code, stays active and shows 55 percent. The switched-off voucher is not redrawn and
     * stays in the window as it was subscribed, active false.
     */
    public function testADiscountEditReachesTheOpenWindowUnderTheSameRowKey(): void
    {
        $payload = $this->subscribe(self::GUEST_KEY)[0][PageResponseSignalData::payload];
        $rows = $payload[PagePayload::windows][EcommerceShopTableContext::vouchers][TableWindowSignalData::rows];
        self::assertFalse(self::voucherSlot($rows, $this->inactiveId)[VoucherTableRow::active]);

        Hilos::$db->vouchers[$this->activeId]->actions->update($this->activeCode, 55, true);
        $row = Database::sql('SELECT * FROM voucher WHERE id = ?', [$this->activeId])->rows()[0];
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::vouchers,
            (string)$this->activeId,
            $row,
        ));
        Hilos::$browser->record(SourceChange::dbUpdated(
            EcommerceShopDbContext::vouchers,
            (string)$this->activeId,
            $row,
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $deltas = [];
        $trace = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $accept = $signal->data instanceof WebSocketSignalData ? $signal->data->targetAcceptKey : null;
            $trace[] = ($accept ?? 'unaddressed') . ' ' . $signal->signalName->getName();
            if (
                $accept !== self::GUEST_KEY
                || !$signal->data instanceof WebSocketSignalData
                || !$signal->data->data instanceof TableViewportDeltaDTO
            ) {
                continue;
            }
            $deltas[] = $signal->data->data;
        }
        self::assertCount(1, $deltas, implode(', ', $trace));
        $delta = $deltas[0];
        self::assertSame(AdminVouchersPage::PAGE, $delta->page);
        self::assertSame(EcommerceShopTableContext::vouchers, $delta->tableKey);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        self::assertSame((string)$this->activeId, (string)$delta->rowKey);
        self::assertNotNull($delta->row);
        self::assertSame((string)$this->activeId, (string)$delta->row[PagePayload::rowKey]);
        self::assertSame([
            VoucherTableRow::id => $this->activeId,
            VoucherTableRow::code => $this->activeCode,
            VoucherTableRow::discountPercent => 55,
            VoucherTableRow::active => true,
        ], $delta->row[PagePayload::slots][VouchersTable::ROW_SLOT]);
        $window = Hilos::$sr->getTableViewport(self::GUEST_KEY, EcommerceShopTableContext::vouchers);
        self::assertNotNull($window);
        self::assertTrue($window->hasRow((string)$this->inactiveId));
        self::assertTrue($window->hasRow((string)$this->activeId));
    }

    /**
     * Writes a person past the users library and drops a verdict an earlier case of this process may have
     * remembered for the same id.
     *
     * @param string $name Name of the person
     * @param bool $admin Whether the person is an administrator
     * @return int Id of the new person
     */
    private static function person(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$name, (int)$admin]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);

        return $userId;
    }

    /**
     * Subscribes one connection with an open vouchers window and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @return list<array<string, mixed>> Wire arrays of the page answers, exactly one
     */
    private function subscribe(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [EcommerceShopTableContext::vouchers => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage(
            AdminVouchersPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminVouchersPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new AdminVouchersPage(new VouchersLibraryAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        $payloads = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                $payloads[] = $signal->data->data->toArray();
            }
        }
        self::assertCount(1, $payloads);

        return $payloads;
    }

    /**
     * @param list<array<string, mixed>> $rows Window rows
     * @param int $voucherId Voucher to find
     * @return array<string, mixed> Fields of its voucher slot
     */
    private static function voucherSlot(array $rows, int $voucherId): array
    {
        foreach ($rows as $row) {
            if ((string)$row[PagePayload::rowKey] === (string)$voucherId) {
                return $row[PagePayload::slots][VouchersTable::ROW_SLOT];
            }
        }
        self::fail("No voucher row for {$voucherId}");
    }
}
