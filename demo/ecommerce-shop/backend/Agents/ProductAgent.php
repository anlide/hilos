<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\ProductAvailability;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Runtime\View\Item\Connection;
use Demo\EcommerceShop\Runtime\View\Item\ProductViewerCount;
use Demo\EcommerceShop\ShopFront\ProductReading;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Throwable;

/**
 * Agent of one address of the storefront's product page (`/product/{slug}`).
 *
 * Indexed by the address: it comes up with the first visitor of that address and goes to sleep
 * after the last one has left and the idle window has passed. Any non-empty address is one - an
 * address may name no product at all, and the agent of an address a product had before a rename
 * reads that product under its new name. The agent reads the product, its category and stock
 * from their libraries' collections. Every reading asks the collections anew.
 */
final class ProductAgent extends AbstractAgent
{
    /**
     * @var list<string> The catalog rows read for the product page
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productSlugs,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::categories,
    ];

    public const string AGENT_TYPE = AgentType::PRODUCT;

    public const array AGENT_SIGNALS = [
        EcommerceShopSignalConstants::CART_PRODUCT_ADD_DONE => [
            AgentSignalConfigKey::INDEX_FIELD => CartAddProductDoneSignalData::productSlug,
            AgentSignalConfigKey::DTO => CartAddProductDoneSignalData::class,
        ],
    ];

    public const array READS_RT = [EcommerceShopRtContext::connections];

    public const array OWNS_RT_ROWS = [EcommerceShopRtContext::productViewerCounts => TruthSourceOperation::BY_KIND];

    private const string USER_VIEWER_PREFIX = 'user:';
    private const string SESSION_VIEWER_PREFIX = 'session:';

    /** @var string The page address this agent answers for */
    private readonly string $address;

    /** @var array<string, ?string> Served tabs keyed by accept key, with null while awaiting an identity */
    private array $viewers = [];

    /** @var array<string, int> Number of tabs per viewer */
    private array $viewerGroups = [];

    /** @var ?int Resource of the subscriptions represented by this audience */
    private ?int $viewedProductId = null;

    /** @var bool Whether the latest aggregate still needs FIFO admission */
    private bool $viewerCountPending = false;

    /**
     * @param string $agentIndex The page address; its form is not checked, an address may name nothing
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('ProductAgent requires non-empty agentIndex (product page address)');
        }
        $this->agentIndex = $agentIndex;
        $this->address = $agentIndex;
    }

    /**
     * Receives the owner's outcome at the original product address.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity, unused
     * @param string $name Routed signal name
     * @throws AgentUnknownSignalException When this agent does not handle the signal
     * @throws InvalidAgentSignalPayloadException When the name and payload disagree
     * @throws AgentException When the outcome belongs to another address or action
     * @throws InvalidArgumentException When the acknowledgement cannot be queued
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::CART_PRODUCT_ADD_DONE:
                $done = $data->data;
                if (!$done instanceof CartAddProductDoneSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, CartAddProductDoneSignalData::class, $done);
                }

                $this->answerCartAdd($done);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @return string The page address this agent answers for
     */
    public function address(): string
    {
        return $this->address;
    }

    /**
     * Reads the product the address names, its category and stock, anew on every call.
     *
     * Looks for an exact current address first, then an exact former address. The returned
     * product's slug tells the two apart ({@see ProductReading}).
     *
     * @return ?ProductReading The catalog reading, or null when the address names no product
     * @throws LogicException When a collection's class constants are not configured
     * @throws InvalidArgumentException When a column names no field of its entity, or a loaded object does not match
     * @throws DatabaseException When the rows cannot be read
     * @throws ItemNotFoundException When a required product or category is missing
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws HilosException When the database context or an item getter refuses the read
     */
    public function reading(): ?ProductReading
    {
        $product = Hilos::$db->products->whereColumnIs(EntityProduct::slug, $this->address)->first();
        // A database comparison can match even when the route carries extra trailing spaces.
        if ($product === null || $product->slug !== $this->address) {
            $formerAddress = Hilos::$db->productSlugs->whereColumnIs(EntityProductSlug::slug, $this->address)->first();
            if ($formerAddress === null || $formerAddress->slug !== $this->address) {
                return null;
            }
            $product = Hilos::$db->products[$formerAddress->productId];
        }

        $units = Hilos::$db->productWarehouseStocks->totalsOfProducts($product->id)->of($product->id)->unitCount;

        return new ProductReading($product, Hilos::$db->categories[$product->categoryId], $units);
    }

    /**
     * @param string $collection Runtime collection whose owned keys are requested
     * @return list<string> Only this address in the viewer count collection
     */
    public function ownedRtRowKeys(string $collection): array
    {
        return $collection === EcommerceShopRtContext::productViewerCounts ? [$this->address] : [];
    }

    /**
     * Restores answered tabs from this worker's surviving mirror before the next page answer.
     * A tab whose connection identity has yet to arrive retains its pending membership.
     *
     * @throws RtActionsStateCollectionNullException When the connections or viewer counts collection is unavailable
     * @throws HilosException When the catalog read or aggregate write fails
     */
    public function onStart(): void
    {
        $acceptKeys = Hilos::$sr->acceptKeysServedBy(self::AGENT_TYPE, $this->address);
        if ($acceptKeys === []) {
            return;
        }
        $reading = $this->reading();
        if ($reading === null || $reading->product->slug !== $this->address
            || $reading->availability === ProductAvailability::NOT_PLACED) {
            return;
        }

        foreach ($acceptKeys as $acceptKey) {
            $resource = ProductPage::viewerSubscriptionResource($acceptKey, $this->address);
            if ($resource?->idString !== (string)$reading->product->id) {
                continue;
            }
            $this->viewedProductId = $reading->product->id;
            $this->updateViewerMembership($acceptKey, self::viewerKey(Hilos::$rt->connections[$acceptKey]), stays: true);
        }
        if ($this->viewers === []) {
            return;
        }

        $this->publishViewerCount();
        foreach (array_keys($this->viewers) as $acceptKey) {
            ProductPage::publishViewers($this, (string)$acceptKey);
        }
    }

    /**
     * Reads the applied identities, including writes still awaiting local dispatch.
     *
     * Prepares the audience for the answer without publishing or counting the new tab yet.
     *
     * @param string $acceptKey Connection about to receive the card
     * @param int $productId Product named by that card
     * @return ProductViewerAnswer Audience for the card and its addressed count
     * @throws RtActionsStateCollectionNullException When the connections collection is unavailable
     */
    public function prepareViewerAnswer(string $acceptKey, int $productId): ProductViewerAnswer
    {
        $connections = Hilos::$rt->connections;
        $viewers = [];
        if ($this->viewedProductId === $productId) {
            foreach (array_keys($this->viewers) as $recipient) {
                $resource = ProductPage::viewerSubscriptionResource((string)$recipient, $this->address);
                if ($resource?->idString === (string)$productId) {
                    $viewers[$recipient] = self::viewerKey($connections[$recipient]);
                }
            }
        }
        $viewers[$acceptKey] = self::viewerKey($connections[$acceptKey]);
        $groups = array_count_values(array_filter($viewers, static fn (?string $viewerKey): bool => $viewerKey !== null));

        return new ProductViewerAnswer($viewers, $groups, count($groups) - (int)($viewers[$acceptKey] !== null));
    }

    /**
     * Records a connection whose full response named this product.
     *
     * @param string $acceptKey Connection that received the full card
     * @param int $productId Product id from the bound resource
     * @param ProductViewerAnswer $answer Prepared audience carried by this answer
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the aggregate write
     */
    public function productPageAnswered(string $acceptKey, int $productId, ProductViewerAnswer $answer): void
    {
        $before = count($this->viewerGroups);
        $previous = $this->viewers;
        $resourceChanged = $this->viewedProductId !== $productId;
        $this->viewers = $answer->viewers;
        $this->viewerGroups = $answer->groups;
        $this->viewedProductId = $productId;
        $after = count($this->viewerGroups);

        if ($resourceChanged || $before !== $after || $this->viewerCountPending) {
            $this->publishViewerCount();
        }
        foreach ($this->viewers as $recipient => $viewerKey) {
            if ((string)$recipient !== $acceptKey
                && $before - (int)isset($previous[$recipient]) !== $after - (int)($viewerKey !== null)
            ) {
                ProductPage::publishViewers($this, (string)$recipient);
            }
        }
    }

    /**
     * Releases a tab even if its connection row is already gone.
     *
     * @param string $acceptKey Connection no longer served at this address
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the aggregate write
     */
    public function productPageLeft(string $acceptKey): void
    {
        if (array_key_exists($acceptKey, $this->viewers)) {
            $this->replaceViewer($acceptKey, null, stays: false);
        }
    }

    /**
     * @param string $acceptKey Connection that may still be served by this agent
     * @param PageResource $resource Resource currently bound to the connection
     * @return ?int Live personal count for this resource, or null if this tab is no longer served
     */
    public function publishedOtherViewers(string $acceptKey, PageResource $resource): ?int
    {
        if ($this->viewedProductId === null || (string)$this->viewedProductId !== $resource->idString
            || !array_key_exists($acceptKey, $this->viewers)
        ) {
            return null;
        }

        return count($this->viewerGroups) - (int)($this->viewers[$acceptKey] !== null);
    }

    /**
     * @param RtSyncCreatedSignalData $data Runtime creation already applied here
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws RtActionsStateCollectionNullException When the connections or viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the connection read or aggregate write
     */
    public function onSignalRtSyncCreated(RtSyncCreatedSignalData $data, string $source, string $name): void
    {
        $this->refreshViewer($data->collectionKey, $data->stateId);
    }

    /**
     * @param RtSyncUpdatedSignalData $data Runtime patch already applied here
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws RtActionsStateCollectionNullException When the connections or viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the connection read or aggregate write
     */
    public function onSignalRtSyncUpdated(RtSyncUpdatedSignalData $data, string $source, string $name): void
    {
        $this->refreshViewer($data->collectionKey, $data->stateId);
    }

    /**
     * @param RtSyncDeletedSignalData $data Runtime deletion already applied here
     * @param string $source Signal source
     * @param string $name Signal name
     * @throws RtActionsStateCollectionNullException When the connections or viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the connection read or aggregate write
     */
    public function onSignalRtSyncDeleted(RtSyncDeletedSignalData $data, string $source, string $name): void
    {
        $this->refreshViewer($data->collectionKey, $data->stateId);
    }

    /** @return bool Whether the last aggregate still needs to be admitted */
    public function hasWorkInFlight(): bool
    {
        return $this->viewerCountPending;
    }

    /**
     * Retries only the latest aggregate when the ordinary FIFO previously refused it.
     *
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the aggregate for a reason other than backpressure
     */
    public function onTick(): void
    {
        if ($this->viewerCountPending) {
            $this->publishViewerCount();
        }
    }

    /**
     * Releases the row before the worker gives up its claim. The worker holds the stop report
     * behind an owed cleanup, so a successor cannot publish on top of that old removal.
     *
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses to prepare the cleanup
     */
    public function onStop(): void
    {
        Hilos::$rt->productViewerCounts[$this->address]?->actions->removeAsCleanup();
        $this->viewers = [];
        $this->viewerGroups = [];
        $this->viewedProductId = null;
        $this->viewerCountPending = false;
    }

    /**
     * @param ?Connection $connection Current runtime connection row, if it has arrived
     * @return ?string Person or guest session grouping key, or null while the identity is absent
     */
    private static function viewerKey(?Connection $connection): ?string
    {
        $userId = $connection?->userId;
        if ($userId !== null) {
            return self::USER_VIEWER_PREFIX . $userId;
        }
        $sessionId = $connection?->sessionId;

        return $sessionId === null ? null : self::SESSION_VIEWER_PREFIX . $sessionId;
    }

    /**
     * Reacts only to connection facts of tabs this agent has already answered.
     *
     * @param string $collectionKey Runtime source key
     * @param string $acceptKey Changed row key, an accept key for the connections collection
     * @throws RtActionsStateCollectionNullException When the connections or viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the connection read or aggregate write
     */
    private function refreshViewer(string $collectionKey, string $acceptKey): void
    {
        if ($collectionKey !== EcommerceShopRtContext::connections || !array_key_exists($acceptKey, $this->viewers)) {
            return;
        }
        $resource = ProductPage::viewerSubscriptionResource($acceptKey, $this->address);
        if ($resource === null || (string)$this->viewedProductId !== $resource->idString) {
            $this->productPageLeft($acceptKey);

            return;
        }

        // A replica snapshot can replace a live connection with delete and create. The page
        // mirror still holds that tab, so keep its place pending until the identity arrives.
        $this->replaceViewer($acceptKey, self::viewerKey(Hilos::$rt->connections[$acceptKey]), stays: true);
    }

    /**
     * Changes one tab's membership as a unit before notifying either audience.
     *
     * @param string $acceptKey Connection whose membership changes
     * @param ?string $viewerKey New viewer, or null without an identity
     * @param bool $stays Whether the tab will still be served
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the aggregate write
     */
    private function replaceViewer(string $acceptKey, ?string $viewerKey, bool $stays): void
    {
        $previous = $this->viewers[$acceptKey] ?? null;
        $before = count($this->viewerGroups);
        $this->updateViewerMembership($acceptKey, $viewerKey, $stays);

        $after = count($this->viewerGroups);
        if ($before !== $after || $this->viewerCountPending) {
            $this->publishViewerCount();
        }

        // Only the changed tab can see a different count when the total stayed the same.
        $personalChanged = $before - (int)($previous !== null) !== $after - (int)($viewerKey !== null);
        if ($stays && $personalChanged) {
            ProductPage::publishViewers($this, $acceptKey);
        }
        if ($before !== $after) {
            foreach (array_keys($this->viewers) as $recipient) {
                if ((string)$recipient !== $acceptKey) {
                    ProductPage::publishViewers($this, (string)$recipient);
                }
            }
        }
    }

    /**
     * @param string $acceptKey Tab whose membership changes
     * @param ?string $viewerKey Current viewer identity, or null while it is pending
     * @param bool $stays Whether the tab is still served
     */
    private function updateViewerMembership(string $acceptKey, ?string $viewerKey, bool $stays): void
    {
        $previous = $this->viewers[$acceptKey] ?? null;
        if ($previous !== $viewerKey) {
            if ($previous !== null && --$this->viewerGroups[$previous] === 0) {
                unset($this->viewerGroups[$previous]);
            }
            if ($viewerKey !== null) {
                $this->viewerGroups[$viewerKey] = ($this->viewerGroups[$viewerKey] ?? 0) + 1;
            }
        }
        if ($stays) {
            $this->viewers[$acceptKey] = $viewerKey;
        } else {
            unset($this->viewers[$acceptKey]);
        }
    }

    /**
     * Stores the current total; a full FIFO leaves the local audience intact for the next tick.
     *
     * @throws RtActionsStateCollectionNullException When the viewer counts collection is unavailable
     * @throws HilosException When runtime refuses the aggregate for a reason other than backpressure
     */
    private function publishViewerCount(): void
    {
        $count = count($this->viewerGroups);
        try {
            $current = Hilos::$rt->productViewerCounts[$this->address];
            if ($count === 0 || $this->viewedProductId === null) {
                $current?->actions->remove();
            } elseif ($current === null) {
                Hilos::$rt->productViewerCounts->actions->create($this->address, $this->viewedProductId, $count);
            } elseif ($current->productId !== $this->viewedProductId) {
                $this->replaceCountedProduct($current, $this->viewedProductId, $count);
            } elseif ($current->viewerCount !== $count) {
                $current->actions->setViewerCount($count);
            }
            $this->viewerCountPending = false;
        } catch (RtWriteBackpressureException) {
            $this->viewerCountPending = true;
        }
    }

    /**
     * @param ProductViewerCount $current Row of the product previously served at this address
     * @param int $productId Product now served at this address
     * @param int $count Number of distinct viewers for the new product
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When an application transaction cannot be opened
     * @throws TransactionNotOpenException When the replacement's transaction was closed under it
     * @throws DatabaseConnectionException When the transaction cannot use its connection
     * @throws DatabaseRuntimeException When commit fails
     * @throws AnnouncementFailedException When the committed replacement cannot be announced
     * @throws HilosException When runtime refuses either part of the replacement or the transaction fails
     */
    private function replaceCountedProduct(ProductViewerCount $current, int $productId, int $count): void
    {
        $level = Database::runtimeTransactionLevel();
        if ($level !== null && $level->testScope === null) {
            $current->actions->remove();
            Hilos::$rt->productViewerCounts->actions->create($this->address, $productId, $count);

            return;
        }
        Database::transactionStart();
        try {
            $current->actions->remove();
            Hilos::$rt->productViewerCounts->actions->create($this->address, $productId, $count);
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
    }

    /**
     * Relays the outcome to the connection that clicked, even if it has navigated since then.
     *
     * @param CartAddProductDoneSignalData $done Writer's outcome and original correlation
     * @throws AgentException When the outcome belongs to another address or action
     * @throws InvalidArgumentException When the acknowledgement cannot be queued
     */
    private function answerCartAdd(CartAddProductDoneSignalData $done): void
    {
        $answer = $done->answer;
        if ($done->productSlug !== $this->address || $answer->action !== EcommerceShopSignalConstants::PRODUCT_ADD_TO_CART) {
            throw new AgentException('Cart add outcome does not match this product address and action.');
        }
        if ($answer->requestId === null) {
            return;
        }

        if ($answer->error === null) {
            $this->sendActionSuccess($answer->acceptKey, $answer->action, $answer->requestId);
        } else {
            $this->sendActionFail($answer->acceptKey, $answer->action, $answer->requestId, $answer->error);
        }
    }
}
