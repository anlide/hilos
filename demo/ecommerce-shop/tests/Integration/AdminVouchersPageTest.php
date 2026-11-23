<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Voucher as EntityVoucher;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminVouchersPage;
use Demo\EcommerceShop\Pages\DTO\VoucherUpdateActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Voucher\VouchersTable;
use Demo\EcommerceShop\Tables\Voucher\VoucherTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableViewportOwnCreateDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\DbSyncApplicator;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
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

    /** The author gets the tracked success and a placed insert, and the observer gets a tail append. */
    public function testAnAdminAddsAVoucherToBothOpenWindows(): void
    {
        $sort = TableSortOrderDTO::of(new TableSortDTO(VoucherTableRow::code));
        $this->subscribe(self::ADMIN_KEY, $sort);
        $this->subscribe(self::VISITOR_KEY, $sort);
        $code = strtoupper('zz' . RandomHelper::hex(8));
        $requestId = 'req-voucher-create';

        $mirror = new class implements SourceMirrorSubscriberInterface {
            public bool $armed = true;
            public ?SourceChange $created = null;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed && $change->sourceKey === EcommerceShopDbContext::vouchers
                    && $change->mutationType === TableMutationType::Create
                ) {
                    $this->created = $change;
                }
            }
        };
        SourceChangeBus::subscribe($mirror);
        try {
            $reply = $this->dispatchCreate(self::ADMIN_KEY, "  " . strtolower($code) . " \t", ' 0100 ', false, $requestId);
        } finally {
            $mirror->armed = false;
        }

        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame($requestId, $reply->requestId);
        self::assertSame(EcommerceShopSignalConstants::VOUCHER_CREATE, $reply->action);
        self::assertSame(sprintf('Voucher “%s” added.', $code), $reply->message);
        $rows = Database::sql('SELECT id, code, discount_percent, active FROM voucher WHERE code = ?', [$code])->rows();
        self::assertCount(1, $rows);
        [$stored] = $rows;
        self::assertSame($code, $stored[EntityVoucher::code]);
        self::assertSame('100', $stored[EntityVoucher::discount_percent]);
        self::assertSame('0', $stored[EntityVoucher::active]);

        // The rollback scope holds browser reactions. Feed the actual ORM create fact into the browser buffer.
        self::assertNotNull($mirror->created);
        self::assertSame($stored[EntityVoucher::id], $mirror->created->sourceId);
        Hilos::$browser->record($mirror->created);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = self::frames([
            SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE => self::ADMIN_KEY,
            SignalTypeConstants::TABLE_VIEWPORT_APPEND => self::VISITOR_KEY,
        ]);
        $ownCreate = $frames[SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE]->data;
        $append = $frames[SignalTypeConstants::TABLE_VIEWPORT_APPEND]->data;
        self::assertInstanceOf(TableViewportOwnCreateDTO::class, $ownCreate);
        self::assertInstanceOf(TableViewportAppendDTO::class, $append);
        self::assertSame($requestId, $ownCreate->requestId);
        foreach ([$ownCreate, $append] as $frame) {
            self::assertSame(AdminVouchersPage::PAGE, $frame->page);
            self::assertSame(EcommerceShopTableContext::vouchers, $frame->tableKey);
            self::assertSame((string)$stored[EntityVoucher::id], (string)$frame->row[PagePayload::rowKey]);
            self::assertSame([
                VoucherTableRow::id => (int)$stored[EntityVoucher::id],
                VoucherTableRow::code => $code,
                VoucherTableRow::discountPercent => 100,
                VoucherTableRow::active => false,
            ], $frame->row[PagePayload::slots][VouchersTable::ROW_SLOT]);
        }
    }

    /** A canonical duplicate and invalid whole percentages are refused without changing the stored vouchers. */
    public function testARefusedCreateLeavesTheVouchersIntact(): void
    {
        $stored = self::storedVouchers();
        $reply = $this->dispatchCreate(self::ADMIN_KEY, strtolower($this->activeCode), '20', false, 'req-duplicate');
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
        self::assertSame('req-duplicate', $reply->requestId);
        self::assertSame(EcommerceShopSignalConstants::VOUCHER_CREATE, $reply->action);
        self::assertSame('A voucher with this code already exists', $reply->reason);
        self::assertSame($stored, self::storedVouchers());

        $code = 'refused' . RandomHelper::hex(8);
        foreach (['zero' => '0', 'above-max' => '101', 'fraction' => '1.5', 'blank' => ' ', 'overflow' => str_repeat('9', 30)] as $case => $input) {
            $requestId = "req-discount-{$case}";
            $reply = $this->dispatchCreate(self::ADMIN_KEY, $code, $input, true, $requestId);
            self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
            self::assertSame($requestId, $reply->requestId);
            self::assertSame(EcommerceShopSignalConstants::VOUCHER_CREATE, $reply->action);
            self::assertSame('Discount must be a whole percent from 1 to 100', $reply->reason);
            self::assertSame($stored, self::storedVouchers());
        }
    }

    /** A direct create from a viewer is refused even with a valid payload and an open window. */
    public function testViewModeRefusesVoucherCreate(): void
    {
        $this->subscribe(self::VISITOR_KEY);
        $stored = self::storedVouchers();
        $reply = $this->dispatchCreate(self::VISITOR_KEY, 'viewer' . RandomHelper::hex(8), '10', true, 'req-viewer');
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
        self::assertSame('req-viewer', $reply->requestId);
        self::assertSame(EcommerceShopSignalConstants::VOUCHER_CREATE, $reply->action);
        self::assertSame(ActionViewModeException::ERROR_CODE, $reply->errorCode);
        self::assertSame($stored, self::storedVouchers());
    }

    /** A tracked edit keeps the voucher ID and redraws the same row in both open windows. */
    public function testAnAdminUpdatesAVoucherInBothOpenWindows(): void
    {
        $sort = TableSortOrderDTO::of(new TableSortDTO(VoucherTableRow::code));
        $this->subscribe(self::ADMIN_KEY, $sort);
        $this->subscribe(self::VISITOR_KEY, $sort);
        $code = $this->activeCode . 'NEW';
        $requestId = 'req-voucher-update';
        $dto = new VoucherUpdateActionDTO($this->activeId, '  ' . strtolower($code) . ' ', ' 055 ', false, $this->activeCode, 15, true);

        $mirror = new class implements SourceMirrorSubscriberInterface {
            public bool $armed = true;
            public ?SourceChange $updated = null;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed && $change->sourceKey === EcommerceShopDbContext::vouchers
                    && $change->mutationType === TableMutationType::Update
                ) {
                    $this->updated = $change;
                }
            }
        };
        SourceChangeBus::subscribe($mirror);
        try {
            $reply = $this->dispatchUpdate(self::ADMIN_KEY, $dto, $requestId);
        } finally {
            $mirror->armed = false;
        }

        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame(sprintf('Voucher “%s” saved.', $code), $reply->message);
        $stored = Database::sql('SELECT id, code, discount_percent, active FROM voucher WHERE code = ?', [$code])->rows();
        self::assertSame([[
            EntityVoucher::id => (string)$this->activeId,
            EntityVoucher::code => $code,
            EntityVoucher::discount_percent => '55',
            EntityVoucher::active => '0',
        ]], $stored);

        // The rollback scope holds browser reactions. Replay the actual update with its action origin.
        self::assertNotNull($mirror->updated);
        self::assertSame((string)$this->activeId, $mirror->updated->sourceId);
        Hilos::$browser->record($mirror->updated);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $deltas = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);
            self::assertArrayNotHasKey($signal->data->targetAcceptKey, $deltas);
            $deltas[$signal->data->targetAcceptKey] = $signal->data->data;
        }
        self::assertCount(2, $deltas);
        foreach ([self::ADMIN_KEY => true, self::VISITOR_KEY => false] as $acceptKey => $own) {
            self::assertArrayHasKey($acceptKey, $deltas);
            $delta = $deltas[$acceptKey];
            self::assertSame($own, $delta->own);
            self::assertSame(AdminVouchersPage::PAGE, $delta->page);
            self::assertSame(EcommerceShopTableContext::vouchers, $delta->tableKey);
            self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
            self::assertSame((string)$this->activeId, (string)$delta->rowKey);
            self::assertNotNull($delta->row);
            self::assertSame((string)$this->activeId, (string)$delta->row[PagePayload::rowKey]);
            self::assertSame([
                VoucherTableRow::id => $this->activeId,
                VoucherTableRow::code => $code,
                VoucherTableRow::discountPercent => 55,
                VoucherTableRow::active => false,
            ], $delta->row[PagePayload::slots][VouchersTable::ROW_SLOT]);
        }
    }

    /** Each expected value independently protects a newer write to the same voucher. */
    public function testEachStaleExpectedValueRefusesVoucherUpdate(): void
    {
        $dto = new VoucherUpdateActionDTO($this->activeId, $this->activeCode . 'DRAFT', '25', true, $this->activeCode, 15, true);
        $voucher = Hilos::$db->vouchers[$this->activeId];
        foreach ([
            'code' => [$this->activeCode . 'OTHER', 15, true],
            'discount' => [$this->activeCode, 30, true],
            'active' => [$this->activeCode, 15, false],
        ] as $field => [$code, $discountPercent, $active]) {
            $voucher->actions->update($code, $discountPercent, $active);
            $stored = self::storedVouchers();
            $this->assertUpdateRefused(
                $dto,
                "req-stale-{$field}",
                'The voucher changed elsewhere. Review the current values and save again.',
            );
            self::assertSame($stored, self::storedVouchers());
        }
    }

    /** A duplicate code and an invalid raw discount leave both vouchers intact. */
    public function testInvalidVoucherUpdatesDoNotPartiallyWrite(): void
    {
        $stored = self::storedVouchers();
        $this->assertUpdateRefused(
            new VoucherUpdateActionDTO($this->activeId, strtolower($this->inactiveCode), '25', false, $this->activeCode, 15, true),
            'req-update-duplicate',
            'A voucher with this code already exists',
        );
        self::assertSame($stored, self::storedVouchers());

        $this->assertUpdateRefused(
            new VoucherUpdateActionDTO($this->activeId, $this->activeCode . 'DRAFT', '1.5', false, $this->activeCode, 15, true),
            'req-update-discount',
            'Discount must be a whole percent from 1 to 100',
        );
        self::assertSame($stored, self::storedVouchers());
    }

    /** A matching code on a different voucher cannot become the target of the update. */
    public function testVoucherUpdateRequiresTheRequestedId(): void
    {
        $stored = self::storedVouchers();
        $this->assertUpdateRefused(
            new VoucherUpdateActionDTO($this->inactiveId + 1, $this->activeCode, '25', false, $this->activeCode, 15, true),
            'req-update-missing',
            'This voucher no longer exists',
        );
        self::assertSame($stored, self::storedVouchers());
    }

    /** An open view-mode window gives the visitor no right to update a voucher. */
    public function testViewModeRefusesVoucherUpdate(): void
    {
        $this->subscribe(self::VISITOR_KEY);
        $stored = self::storedVouchers();
        $dto = new VoucherUpdateActionDTO($this->activeId, $this->activeCode, '25', false, $this->activeCode, 15, true);
        $reply = $this->dispatchUpdate(self::VISITOR_KEY, $dto, 'req-update-viewer');
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
        self::assertSame(ActionViewModeException::ERROR_CODE, $reply->errorCode);
        self::assertSame($stored, self::storedVouchers());
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
     * @param string $acceptKey Acting connection
     * @param string $code Voucher code as entered
     * @param string $discountPercent Discount as entered
     * @param bool $active Whether the voucher is active
     * @param string $requestId Client-minted request id
     * @return PageActionSuccessSignalData|PageActionErrorSignalData The single tracked acknowledgement
     */
    private function dispatchCreate(
        string $acceptKey,
        string $code,
        string $discountPercent,
        bool $active,
        string $requestId,
    ): PageActionSuccessSignalData|PageActionErrorSignalData {
        return $this->dispatchAction(new WebSocketActionSignalDTO($acceptKey, EcommerceShopSignalConstants::VOUCHER_CREATE, [
            ObjectVoucher::code => $code,
            ObjectVoucher::discountPercent => $discountPercent,
            ObjectVoucher::active => $active,
        ], $requestId));
    }

    /**
     * @param VoucherUpdateActionDTO $dto Update request
     * @param string $requestId Client-minted request id
     * @param string $reason Expected domain refusal
     */
    private function assertUpdateRefused(VoucherUpdateActionDTO $dto, string $requestId, string $reason): void
    {
        $reply = $this->dispatchUpdate(self::ADMIN_KEY, $dto, $requestId);
        self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
        self::assertSame($reason, $reply->reason);
    }

    /**
     * @param string $acceptKey Acting connection
     * @param VoucherUpdateActionDTO $dto Update request
     * @param string $requestId Client-minted request id
     * @return PageActionSuccessSignalData|PageActionErrorSignalData The correlated update acknowledgement
     */
    private function dispatchUpdate(
        string $acceptKey,
        VoucherUpdateActionDTO $dto,
        string $requestId,
    ): PageActionSuccessSignalData|PageActionErrorSignalData {
        $reply = $this->dispatchAction(new WebSocketActionSignalDTO(
            $acceptKey,
            EcommerceShopSignalConstants::VOUCHER_UPDATE,
            $dto->toArray(),
            $requestId,
        ));
        self::assertSame($requestId, $reply->requestId);
        self::assertSame(EcommerceShopSignalConstants::VOUCHER_UPDATE, $reply->action);

        return $reply;
    }

    /**
     * @param WebSocketActionSignalDTO $frame WebSocket action as received by the page router
     * @return PageActionSuccessSignalData|PageActionErrorSignalData The single tracked acknowledgement
     */
    private function dispatchAction(WebSocketActionSignalDTO $frame): PageActionSuccessSignalData|PageActionErrorSignalData
    {
        $agent = new VouchersLibraryAgent();
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
        );
        ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId(), acceptKey: $frame->acceptKey),
            static fn () => $router->dispatchAction($frame, 'websocket'),
        );

        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (in_array($signal->signalName->getName(), [SignalConstants::ACTION_SUCCESS, SignalConstants::ACTION_ERROR], true)) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame($frame->acceptKey, $signal->data->targetAcceptKey);
                $replies[] = $signal->data->data;
            }
        }
        self::assertCount(1, $replies);

        return $replies[0];
    }

    /**
     * @param array<string, string> $targets Signal names and their expected recipients
     * @return array<string, WebSocketSignalData> One frame per expected signal
     */
    private static function frames(array $targets): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if (isset($targets[$name])) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame($targets[$name], $signal->data->targetAcceptKey);
                self::assertArrayNotHasKey($name, $frames);
                $frames[$name] = $signal->data;
            }
        }
        self::assertSame([], array_diff_key($targets, $frames), 'Expected frames missing from the router queue');

        return $frames;
    }

    /** @return list<array<string, mixed>> Stored voucher rows in primary key order */
    private static function storedVouchers(): array
    {
        return Database::sql('SELECT id, code, discount_percent, active FROM voucher ORDER BY id')->rows();
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
     * @param ?TableSortOrderDTO $sort Sort held by the window, if any
     * @return list<array<string, mixed>> Wire arrays of the page answers, exactly one
     */
    private function subscribe(string $acceptKey, ?TableSortOrderDTO $sort = null): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [EcommerceShopTableContext::vouchers => new TableWindowDescriptorDTO(sort: $sort, limit: TableConstants::NO_LIMIT)];
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
