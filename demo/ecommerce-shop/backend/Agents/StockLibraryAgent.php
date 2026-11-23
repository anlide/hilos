<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\ProductWarehousePlaceSignalData;
use Demo\EcommerceShop\Core\Router\DTO\StockSetUnitsSignalData;
use Demo\EcommerceShop\Database\Actions\Collection\ProductWarehouseStocksActions;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Monopolistic library of the shop's stock - the one writer of the units each warehouse keeps of each product.
 *
 * Setting the units of a stock item and putting a unique piece into a warehouse happen here and
 * nowhere else: whatever writes the stock writes in the library's own process, under its claim.
 * One instance runs per cluster, and the placement policy chooses its node. The library alone
 * holds the stock whole; everybody else reads one row by key or queries the database
 * (docs/agents/architecture/entity-libraries.md). The stock has a library of its own, not the
 * products library's, because it is the number that changes most - orders will take from it -
 * and the products library does not become the writer of those changes.
 */
final class StockLibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The stock, owned whole
     */
    public const array OWNS_DB = [
        EcommerceShopDbContext::productWarehouseStocks => TruthSourceOperation::BY_KIND,
    ];

    /**
     * @var list<string> The products and the warehouses the stock is kept of and in: their own libraries
     *     write them, and a stock write only checks the kind of its product and that its warehouse still exists;
     *     a warehouse deleted after that check is refused by the database's reference to it
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::warehouses,
    ];

    public const string AGENT_TYPE = AgentType::STOCK_LIBRARY;

    public const array AGENT_SIGNALS = [
        EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE => ProductWarehousePlaceSignalData::class,
        EcommerceShopSignalConstants::STOCK_SET_UNITS => StockSetUnitsSignalData::class,
    ];

    /** Stock actions arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Declares the stock complete in this process.
     *
     * The stock loads lazily by key everywhere, and a stock write walks the rows of its product,
     * so the holder of the set reads it in once here. The declaration outlives a db-reset and a
     * restore: a collection that was whole reads itself whole again ({@see Objects::reHydrate()}).
     *
     * @throws LogicException When the collection is not mounted, or its entity collection class is not configured
     * @throws DatabaseException When loading the collection from the database fails
     */
    public function onStart(): void
    {
        (Hilos::$db->productWarehouseStocks->getObjectCollection() ?? throw new LogicException('The stock collection is not mounted'))
            ->preloadAll();
    }

    /**
     * The stock is durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Routes placement requests and stock edits to the stock writer in this process.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender as described by {@see SignalSource::describe()}, unused
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When this agent does not declare the signal
     * @throws InvalidAgentSignalPayloadException When the payload does not match the signal name
     * @throws InvalidArgumentException When the outcome cannot be queued
     * @throws HilosException When the collection read guard refuses a placement read in this process
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE:
                $placement = $data->data;
                if (!$placement instanceof ProductWarehousePlaceSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, ProductWarehousePlaceSignalData::class, $placement);
                }

                $this->placeUnique($placement);

                return;

            case EcommerceShopSignalConstants::STOCK_SET_UNITS:
                $ask = $data->data;
                if (!$ask instanceof StockSetUnitsSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, StockSetUnitsSignalData::class, $ask);
                }
                $this->setStockUnits($ask);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Answers the page after the placement has been written or refused.
     *
     * @param ProductWarehousePlaceSignalData $placement Requested placement and reply address
     * @throws InvalidArgumentException When the outcome cannot be queued
     * @throws HilosException When the collection read guard refuses a placement read in this process
     */
    private function placeUnique(ProductWarehousePlaceSignalData $placement): void
    {
        $refusal = null;
        try {
            Hilos::$db->productWarehouseStocks->actions->placeFrom(
                $placement->productId,
                $placement->expectedWarehouseId,
                $placement->warehouseId,
            );
        } catch (WiringRefusal $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
            if ($refusal->isInternal()) {
                $this->logAgentError("Warehouse placement failed for product #{$placement->productId}: {$failure->getMessage()}");
            }
        }

        $this->sendToAgent($placement->replySignal, HandoverAnswerSignalData::to($placement, $refusal));
    }

    /**
     * Judges one edit and sends the outcome back to its gatekeeper.
     *
     * The writer's transaction covers the domain reads and the write. A failure before commit rolls
     * back the row, its journal entry and the held collection state. On commit, the worker's handler
     * accounts for a failed announcement separately, so a committed edit still receives success.
     * A no-op commits without a stock write or announcement.
     *
     * @param StockSetUnitsSignalData $ask Stock edit and response address
     * @throws InvalidArgumentException When the response signal cannot be queued
     */
    private function setStockUnits(StockSetUnitsSignalData $ask): void
    {
        $refusal = null;
        try {
            Database::transaction(
                DatabaseNames::PRIMARY,
                static fn () => Hilos::$db->productWarehouseStocks->actions->setUnitsFrom(
                    $ask->productId,
                    $ask->warehouseId,
                    $ask->expectedUnits,
                    self::parseUnits($ask->units),
                ),
            );
        } catch (WiringRefusal $e) {
            // The collection's read guard already logged the wiring failure.
            $refusal = ActionRefusal::fromThrowable($e);
        } catch (Throwable $e) {
            $refusal = ActionRefusal::fromThrowable($e);
            if ($refusal->isInternal()) {
                $this->logAgentError("Could not set stock for product {$ask->productId} in warehouse {$ask->warehouseId}: {$e->getMessage()}");
            }
        }

        $this->sendToAgent($ask->replySignal, HandoverAnswerSignalData::to($ask, $refusal));
    }

    /**
     * Parses the decimal edit and bounds its magnitude before casting.
     *
     * @param string $rawUnits Units as entered in the browser
     * @return int Units to store
     * @throws ValidationException When the edit has the wrong format or lies outside the stock range
     */
    private static function parseUnits(string $rawUnits): int
    {
        $units = trim($rawUnits);
        if (preg_match('/\A-?[0-9]+\z/', $units) !== 1) {
            throw new ValidationException('Enter a whole number of units.');
        }

        $digits = ltrim($units, '-0');
        if ($digits === '') {
            return 0;
        }
        if ($units[0] === '-') {
            throw new ValidationException(ProductWarehouseStocksActions::NEGATIVE_UNITS_REASON);
        }
        if (strlen($digits) > strlen((string)ProductWarehouseStocksActions::UNITS_MAX)) {
            throw new ValidationException(ProductWarehouseStocksActions::EXCESS_UNITS_REASON);
        }

        return (int)$digits;
    }
}
