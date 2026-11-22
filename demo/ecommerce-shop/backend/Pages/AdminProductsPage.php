<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Files\CatalogPictureFile;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Money\Money;
use Demo\EcommerceShop\Pages\DTO\ProductCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\ProductPicturePublishActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\HilosException;
use Throwable;

/**
 * The shop's products and their category filter, with actions to publish a picture and add a product.
 *
 * @property ProductsLibraryAgent $agent
 */
final class AdminProductsPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_PRODUCTS;

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
        EcommerceShopSignalConstants::PRODUCT_CREATE => ProductCreateActionDTO::class,
        EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH => ProductPicturePublishActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCTS,
    ];

    /**
     * Routes product actions to their typed handlers.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No immediate business payload
     * @throws AgentUnknownActionException When this page does not own the action
     * @throws InvalidActionPayloadException When the payload does not match the action name
     * @throws ValidationException When the fields or the picture are refused, or publication is already in flight
     * @throws LogicException When the files door is missing, the price parser fails or creation raises an unexpected failure
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is invalid
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     * @throws HilosException When the registry cannot be read or product creation fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::PRODUCT_CREATE:
                if (!$dto instanceof ProductCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, ProductCreateActionDTO::class, $dto);
                }
                $this->handleCreate($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::PRODUCT_PICTURE_PUBLISH:
                if (!$dto instanceof ProductPicturePublishActionDTO) {
                    throw new InvalidActionPayloadException($action, ProductPicturePublishActionDTO::class, $dto);
                }
                $this->handlePublish($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
        }
    }

    /**
     * Asks the files door to publish the upload and defers a tracked reply until it answers.
     *
     * @param string $acceptKey Connection the upload belongs to
     * @param ProductPicturePublishActionDTO $dto Picture publication payload
     * @throws LogicException When the files door is missing
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is invalid
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     * @throws ValidationException When another request is already publishing this upload
     */
    private function handlePublish(string $acceptKey, ProductPicturePublishActionDTO $dto): void
    {
        $requestId = $this->currentActionRequestId();
        $this->agent->publishPicture($acceptKey, $dto->clientUploadId, $requestId);
        if ($requestId !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Adds a product with a picture freshly published by the acting person.
     *
     * @param string $acceptKey Acting connection
     * @param ProductCreateActionDTO $dto Product creation payload
     * @throws ValidationException When the kind, the price, another product field or the picture is refused
     * @throws LogicException When the price parser fails or creation raises an unexpected failure
     * @throws HilosException When the registry cannot be read or product creation fails
     */
    private function handleCreate(string $acceptKey, ProductCreateActionDTO $dto): void
    {
        $ownerId = CatalogPictureFile::freshOwnerId($acceptKey, $dto->pictureFileId);
        $price = Money::parse($dto->price);
        $kind = ProductKind::tryFrom($dto->kind) ?? throw new ValidationException('Choose a product kind');

        try {
            $product = Hilos::$db->products->actions->create(
                $dto->name, $dto->categoryId, $kind, $price, $dto->description, $dto->pictureFileId, $ownerId,
            );
        } catch (HilosException $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw new LogicException('The product could not be added', previous: $failure);
        }
        $this->setActionSuccessMessage(sprintf('Product “%s” added.', $product->name));
    }
}
