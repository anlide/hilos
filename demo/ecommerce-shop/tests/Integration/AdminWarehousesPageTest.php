<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopCommandConstants;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminWarehousesPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Warehouse\WarehousesTable;
use Demo\EcommerceShop\Tables\Warehouse\WarehouseTableRow;
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
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
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
 * stock library's frame, which owns neither the connections nor the view mode. The warehouses library,
 * which serves the page, claims the warehouses without its start; its actions and commands run under its
 * own frame.
 */
abstract class AdminWarehousesPageEnvironment extends WarehousesTableEnvironment
{
    private const string TEST_AGENT = 'admin-warehouses-page-test';

    protected ?WarehousesLibraryAgent $warehousesLibrary = null;

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;

    /** Claims the stock for its library beneath, without its start, then the browser, the router, the connections claim and the view mode. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        $this->warehousesLibrary = new WarehousesLibraryAgent();
        OwnershipDeclaration::claimAll($this->warehousesLibrary);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->claimed = true;
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        });
    }

    /**
     * Turns the view mode off and forgets the connections if they were claimed, releases the warehouses
     * library's claim, restores the router and the browser, then releases beneath, in every outcome of the
     * preparation.
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
            if ($this->warehousesLibrary !== null) {
                TruthSourceRegistry::unregisterAgent($this->warehousesLibrary->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->warehousesLibrary->getId()));
                $this->warehousesLibrary = null;
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
 * mode off the page is closed to whoever is not an administrator. An administrator adds a warehouse through
 * the page, a viewer may not, and the test-only command removes a warehouse by its name.
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

    /** On an empty catalog, one warehouse of the case, and a guest, a person without admin rights and an administrator connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
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

    /** An administrator adds a warehouse: the row is stored trimmed, and the success names it. */
    public function testAnAdminAddsAWarehouse(): void
    {
        $name = 'Warehouse ' . RandomHelper::hex(8);
        $this->dispatchAction(self::ADMIN_KEY, [
            ObjectWarehouse::name => "  {$name} ",
            ObjectWarehouse::address => "\tKhreshchatyk St, 22 \n",
        ], 'req-warehouse-create');

        $success = $this->acknowledgement(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $success);
        self::assertSame('req-warehouse-create', $success->requestId);
        self::assertSame(EcommerceShopSignalConstants::WAREHOUSE_CREATE, $success->action);
        self::assertSame(sprintf('Warehouse “%s” added.', $name), $success->message);
        self::assertSame(
            [['name' => $name, 'address' => 'Khreshchatyk St, 22']],
            Database::sql('SELECT name, address FROM warehouse WHERE name = ?', [$name])->rows(),
        );
    }

    /** A name another warehouse already carries is refused with the library's sentence, and no second row is written. */
    public function testAnAdminIsRefusedATakenName(): void
    {
        $this->dispatchAction(self::ADMIN_KEY, [
            ObjectWarehouse::name => $this->warehouseName,
            ObjectWarehouse::address => self::WAREHOUSE_ADDRESS,
        ], 'req-warehouse-create-taken');

        $error = $this->acknowledgement(SignalConstants::ACTION_ERROR, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame('req-warehouse-create-taken', $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::WAREHOUSE_CREATE, $error->action);
        self::assertSame('A warehouse with this name already exists', $error->reason);
        self::assertSame(1, self::storedCount());
    }

    /** With the admin view mode on, the add action is refused to a guest and a non-admin viewer, and no row is written. */
    public function testAGuestAndANonAdminAreRefusedTheAddWhenTheViewModeIsOn(): void
    {
        foreach ([self::GUEST_KEY, self::VISITOR_KEY] as $acceptKey) {
            try {
                PageAccessGate::assertAction(AdminWarehousesPage::class, $acceptKey, EcommerceShopSignalConstants::WAREHOUSE_CREATE);
                self::fail("Expected ActionViewModeException for {$acceptKey}");
            } catch (ActionViewModeException) {
                // Expected
            }
        }
        self::assertSame(1, self::storedCount());
    }

    /** The test-only command removes the warehouse of the given name, and answers a repeat that nothing was removed. */
    public function testTheTestCommandDeletesAWarehouseByItsName(): void
    {
        self::assertSame([EcommerceShopCommandConstants::deleted => true], $this->command($this->warehouseName)->payload);
        self::assertSame(0, self::storedCount());
        self::assertSame([EcommerceShopCommandConstants::deleted => false], $this->command($this->warehouseName)->payload);
        self::assertSame(0, self::storedCount());
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
     * Sends one warehouse create frame through the real page router under the warehouses library.
     *
     * @param string $acceptKey Acting connection
     * @param array<string, mixed> $payload Action payload
     * @param string $requestId Client-minted request id
     */
    private function dispatchAction(string $acceptKey, array $payload, string $requestId): void
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $agent = $this->warehousesLibrary;
        self::assertNotNull($agent);
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
        );

        ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId(), acceptKey: $acceptKey),
            static fn () => $router->dispatchAction(
                new WebSocketActionSignalDTO($acceptKey, EcommerceShopSignalConstants::WAREHOUSE_CREATE, $payload, $requestId),
                'websocket',
            ),
        );
    }

    /**
     * Reads one acknowledgement signal of the given name.
     *
     * @param string $name Expected signal name
     * @param string $acceptKey Expected target accept key
     * @return SignalDataInterface Signal payload data
     */
    private function acknowledgement(string $name, string $acceptKey): SignalDataInterface
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame($acceptKey, $signal->data->targetAcceptKey);
                $frames[] = $signal->data->data;
            }
        }
        self::assertCount(1, $frames);

        return $frames[0];
    }

    /**
     * Delivers the test-only delete command to the warehouses library under its frame.
     *
     * @param string $name Name of the warehouse to remove
     * @return CommandReplyDTO The single successful reply
     */
    private function command(string $name): CommandReplyDTO
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $agent = $this->warehousesLibrary;
        self::assertNotNull($agent);
        $command = EcommerceShopCommandConstants::WAREHOUSE_TEST_DELETE;
        $request = Hilos::$sr->createCommandPayloadDTO(
            $command,
            new CommandRequestDTO('warehouse-delete', $command, [EcommerceShopCommandConstants::name => $name]),
        );
        ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId()),
            static fn () => $agent->onSignalCommand($request, 'command', $command),
        );
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }
        self::assertCount(1, $replies, 'The command answers exactly once');
        self::assertSame('warehouse-delete', $replies[0]->correlationId);
        self::assertTrue($replies[0]->isOk());

        return $replies[0];
    }

    /** @return int Number of stored warehouses, read past the ORM */
    private static function storedCount(): int
    {
        return (int)Database::sql('SELECT COUNT(*) AS n FROM warehouse')->field('n');
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
