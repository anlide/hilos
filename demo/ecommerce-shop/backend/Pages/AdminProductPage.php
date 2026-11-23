<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Core\Router\DTO\ProductWarehousePlaceSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Files\CatalogPictureFile;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Money\Money;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductUpdateActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductWarehousePlaceActionDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HandoverGatekeeperTrait;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalSource;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;
use Throwable;

/**
 * One product of the shop under the head of its administration section, addressed by its id
 * (`/hilos/app/product/{productId}`): the page carries the product's card and the categories and
 * warehouses lists the card's names are read from. Administrators can edit its catalog fields.
 *
 * @property ProductsLibraryAgent $agent
 */
final class AdminProductPage extends AbstractPage
{
    use HandoverGatekeeperTrait;

    public const string PAGE = PageConstants::ADMIN_PRODUCT;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the products library agent, which owns the products and their addresses
     * and neither users nor connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PRODUCTS_LIBRARY;

    public const array ACTIONS = [
        EcommerceShopSignalConstants::PRODUCT_UPDATE => ProductUpdateActionDTO::class,
        EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE => ProductWarehousePlaceActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            EcommerceShopSignalConstants::ADMIN_PRODUCT_WAREHOUSE_PLACE_DONE => HandoverAnswerSignalData::class,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCT,
    ];

    /**
     * Answers a move to another product with the whole page under the new number.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Routes catalog edits and warehouse placement requests.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No business payload, with placement acknowledged after the stock library answers
     * @throws AgentUnknownActionException When this page does not own the action
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws InvalidArgumentException When the placement request cannot be queued
     * @throws InvalidFormatException When the placement request has an invalid id
     * @throws ItemNotFoundForUpdateException When the product no longer exists
     * @throws ValidationException When the version, fields or picture are refused
     * @throws LogicException When the price parser cannot run or the save fails unexpectedly
     * @throws HilosException When the product or file cannot be read or the update fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::PRODUCT_UPDATE:
                if (!$dto instanceof ProductUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, ProductUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE:
                if (!$dto instanceof ProductWarehousePlaceActionDTO) {
                    throw new InvalidActionPayloadException($action, ProductWarehousePlaceActionDTO::class, $dto);
                }
                $this->handleWarehousePlacement($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
        }
    }

    /**
     * Relays the stock library's outcome to the administrator who requested placement.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender as described by {@see SignalSource::describe()}, unused
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When this page does not declare the signal
     * @throws InvalidAgentSignalPayloadException When the payload does not match the signal name
     * @throws InvalidArgumentException When the acknowledgement cannot be named
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::ADMIN_PRODUCT_WAREHOUSE_PLACE_DONE:
                $answer = $data->data;
                if (!$answer instanceof HandoverAnswerSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, HandoverAnswerSignalData::class, $answer);
                }

                $this->answerHandover($answer);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Binds the Overview subscription to the product named by its primary id.
     *
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Raw route parameters
     * @return PageResource The product row whose deletion refuses this subscription
     * @throws MissingPageRouteParamException When the product id is absent or empty
     * @throws InvalidPageRouteParamException When the product id is not a positive integer
     * @throws PageResourceNotFoundException When no product has the id
     * @throws PageInternalErrorException When the resource declaration is empty
     * @throws LogicException When the products collection class constants are not configured
     * @throws InvalidArgumentException When a loaded product does not match the collection
     * @throws DatabaseException When the product cannot be looked up
     */
    protected function resolvePageResource(string $acceptKey, PageRouteParams $params): PageResource
    {
        $address = AdminProductPageSubscribeParams::fromPageRouteParams($params);
        if (!isset(Hilos::$db->products[$address->productId])) {
            throw new PageResourceNotFoundException('Unknown product: ' . $address->productId);
        }

        return PageResource::db(EcommerceShopDbContext::products, $address->productId);
    }

    /**
     * Checks the version and saves the edit in one handler of the products library, the only
     * writer of the product.
     *
     * @param string $acceptKey Acting connection
     * @param ProductUpdateActionDTO $dto Product edit payload
     * @throws ItemNotFoundForUpdateException When the product no longer exists
     * @throws ValidationException When the version, fields or picture are refused
     * @throws LogicException When the price parser cannot run, wiring is missing or the save fails unexpectedly
     * @throws HilosException When the product or file cannot be read or the update fails
     */
    private function handleUpdate(string $acceptKey, ProductUpdateActionDTO $dto): void
    {
        $product = Hilos::$db->products[$dto->productId]
            ?? throw new ItemNotFoundForUpdateException('This product no longer exists');

        try {
            if ($dto->expectedVersion !== $product->editVersion()) {
                throw new ValidationException('The product changed elsewhere. Review the current values and save again.');
            }

            $ownerId = $dto->pictureFileId === null ? null : CatalogPictureFile::freshOwnerId($acceptKey, $dto->pictureFileId);
            $product->actions->update(
                $dto->name,
                $dto->categoryId,
                $product->kind,
                Money::parse($dto->price),
                $dto->description,
                $dto->pictureFileId,
                $ownerId,
            );
        } catch (HilosException $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw new LogicException('The product could not be saved', previous: $failure);
        }
        $this->setActionSuccessMessage(sprintf('Product “%s” saved.', $product->name));
    }

    /**
     * Defers the acknowledgement until the stock library has judged the request against its own rows.
     *
     * @param string $acceptKey Acting connection
     * @param ProductWarehousePlaceActionDTO $dto Destination and the placement the administrator saw
     * @throws InvalidArgumentException When the placement request cannot be queued
     * @throws InvalidFormatException When the placement request has an invalid id
     */
    private function handleWarehousePlacement(string $acceptKey, ProductWarehousePlaceActionDTO $dto): void
    {
        $this->forward(
            EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE,
            new ProductWarehousePlaceSignalData(
                productId: $dto->productId,
                warehouseId: $dto->warehouseId,
                expectedWarehouseId: $dto->expectedWarehouseId,
                replySignal: EcommerceShopSignalConstants::ADMIN_PRODUCT_WAREHOUSE_PLACE_DONE,
                acceptKey: $acceptKey,
                requestId: $this->currentActionRequestId(),
                action: $dto->getAction(),
                successMessage: null,
            ),
        );
    }
}
