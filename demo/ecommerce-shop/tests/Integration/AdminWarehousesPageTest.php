<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminWarehousesPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Warehouse\WarehousesTable;
use Demo\EcommerceShop\Tables\Warehouse\WarehouseTableRow;
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
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Mounts the shop's browser and its own signal router over the warehouses environment, turns the admin
 * view mode on, and claims the connections register the page gate reads a subscriber's identity from.
 *
 * The runtime writes run outside any agent, as the daemon's do: the environment beneath runs under the
 * stock library's frame, which owns neither the connections nor the view mode.
 */
abstract class AdminWarehousesPageEnvironment extends WarehousesTableEnvironment
{
    private const string TEST_AGENT = 'admin-warehouses-page-test';

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;

    /** Starts the stock library beneath, then the browser, the router, the connections claim and the view mode. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
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
     * the browser, then releases beneath, in every outcome of the preparation.
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
 * The warehouses admin page in the admin view mode: a guest and a signed-in person without admin rights
 * see every field of a warehouse in the first answer, an administrator sees no mark at all, and with the
 * mode off the page is closed to whoever is not an administrator.
 *
 * Warehouses are rows written straight into their table, as the warehouses table's own test does; so are the
 * two people, before any read warms the users collection. The rollback scope proves reads of written rows.
 */
#[IntegrationTest]
final class AdminWarehousesPageTest extends AdminWarehousesPageEnvironment
{
    private const string GUEST_KEY = 'admin-warehouses-guest';
    private const string VISITOR_KEY = 'admin-warehouses-visitor';
    private const string ADMIN_KEY = 'admin-warehouses-admin';

    private int $warehouseId;
    private string $warehouseName;

    /** One warehouse of the case, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        $this->warehouseName = 'Warehouse ' . RandomHelper::hex(8);
        $this->warehouseId = self::warehouse($this->warehouseName);
        $visitorId = self::person('Warehouses visitor', false);
        $adminId = self::person('Warehouses admin', true);
        self::unattended(static function () use ($visitorId, $adminId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
        });
    }

    /** A viewer receives the warehouses window in the first answer, and the case's warehouse with all five fields shown. */
    public function testAViewerSeesTheWarehouseWithEveryFieldShown(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            $rows = $this->window($this->subscribe($acceptKey))[TableWindowSignalData::rows];
            $slot = null;
            foreach ($rows as $row) {
                $candidate = $row[PagePayload::slots][WarehousesTable::ROW_SLOT];
                if ($candidate[WarehouseTableRow::id] === $this->warehouseId) {
                    $slot = $candidate;
                    break;
                }
            }
            self::assertNotNull($slot, "Warehouse row not found for {$acceptKey}");
            self::assertSame([
                WarehouseTableRow::id => $this->warehouseId,
                WarehouseTableRow::name => $this->warehouseName,
                WarehouseTableRow::address => self::WAREHOUSE_ADDRESS,
                WarehouseTableRow::productCount => 0,
                WarehouseTableRow::unitCount => 0,
            ], $slot);
            foreach ($slot as $field => $value) {
                self::assertFalse(HiddenValue::isMark($value), "field {$field} is a mark for {$acceptKey}");
            }
        }
    }

    /** An administrator is sent the page without a single mark. */
    public function testAnAdminIsSentThePageWithoutASingleMark(): void
    {
        $payloads = $this->subscribe(self::ADMIN_KEY);

        self::assertArrayHasKey(EcommerceShopTableContext::warehouses, $payloads[0][PageResponseSignalData::payload][PagePayload::windows]);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payloads, JSON_THROW_ON_ERROR));
    }

    /** With the view mode off the page is closed to a person without admin rights. */
    public function testANonAdminIsRefusedWhenTheViewModeIsOff(): void
    {
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AdminWarehousesPage::class, self::VISITOR_KEY);
    }

    /**
     * Writes a person past the users library, before any read warms the users collection, and drops a
     * verdict an earlier case of this process may have remembered for the same id.
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
     * Subscribes one connection with an open warehouses window and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @return list<array<string, mixed>> Wire arrays of the page answers, exactly one
     */
    private function subscribe(string $acceptKey): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $windows = [EcommerceShopTableContext::warehouses => new TableWindowDescriptorDTO(limit: TableConstants::NO_LIMIT)];
        Hilos::$sr->subscribeToPage(
            AdminWarehousesPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminWarehousesPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new AdminWarehousesPage(new WarehousesLibraryAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
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
     * Reads the warehouses window out of the page answers.
     *
     * @param list<array<string, mixed>> $payloads Wire arrays of the page answers
     * @return array<string, mixed> The window of the warehouses table
     */
    private function window(array $payloads): array
    {
        return $payloads[0][PageResponseSignalData::payload][PagePayload::windows][EcommerceShopTableContext::warehouses];
    }
}
