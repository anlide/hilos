<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Files\CatalogPictureFile;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Money\Money;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductUpdateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageResource;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
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
     * Routes catalog edits to the product update handler.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No business payload
     * @throws AgentUnknownActionException When this page does not own the action
     * @throws InvalidActionPayloadException When the payload does not match the action name
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

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
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
}
