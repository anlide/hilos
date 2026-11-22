<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Files\CatalogPictureFile;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CategoryCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryMoveUpActionDTO;
use Demo\EcommerceShop\Pages\DTO\CategoryPicturePublishActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;
use Throwable;

/**
 * The shop's categories under the head of its administration section: the page
 * carries the categories table and its own move-up action.
 *
 * It also adds a category: the picture is published as soon as it is uploaded, and the
 * library acknowledges that action when the files door answers; the add itself carries
 * the number of the published file.
 *
 * @property CategoriesLibraryAgent $agent
 */
final class AdminCategoriesPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_CATEGORIES;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the categories library agent, which owns the categories and their
     * addresses and neither users nor connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CATEGORIES_LIBRARY;

    public const array ACTIONS = [
        EcommerceShopSignalConstants::CATEGORY_MOVE_UP => CategoryMoveUpActionDTO::class,
        EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH => CategoryPicturePublishActionDTO::class,
        EcommerceShopSignalConstants::CATEGORY_CREATE => CategoryCreateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_CATEGORIES,
    ];

    /**
     * Routes the category move up, picture publish and create actions to their typed handlers.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Action reply DTO, or null
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ItemNotFoundForUpdateException When category no longer exists
     * @throws ValidationException When category is already first in the catalog, or the picture is not a fresh file
     * @throws DatabaseException When category position exchange, collection loading, the insert or the commit fails
     * @throws InvalidArgumentException When category type does not match the collection, or the publish signal cannot be queued
     * @throws LogicException When categories collection is not configured, another category shows the picture,
     *     the files door is missing, or the create transaction raised something outside the framework's tree
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is one the wire does not allow
     * @throws EmptyValueException When the name or the description is empty
     * @throws ValueTooLongException When the name or the description is longer than its column
     * @throws DuplicateValueException When another category already has this name
     * @throws CreateNotAllowedException When this process may not add a category or its address
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws HilosException When the registry cannot be read, or the right, the database or the collection refuses the write
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::CATEGORY_MOVE_UP:
                if (!$dto instanceof CategoryMoveUpActionDTO) {
                    throw new InvalidActionPayloadException($action, CategoryMoveUpActionDTO::class, $dto);
                }
                $this->handleMoveUp($dto);

                return null;

            case EcommerceShopSignalConstants::CATEGORY_PICTURE_PUBLISH:
                if (!$dto instanceof CategoryPicturePublishActionDTO) {
                    throw new InvalidActionPayloadException($action, CategoryPicturePublishActionDTO::class, $dto);
                }
                $this->handlePublish($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::CATEGORY_CREATE:
                if (!$dto instanceof CategoryCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, CategoryCreateActionDTO::class, $dto);
                }
                $this->handleCreate($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
        }
    }

    /**
     * Moves a category one place up in the catalog.
     *
     * @param CategoryMoveUpActionDTO $dto Move up action payload
     * @throws ItemNotFoundForUpdateException When category no longer exists
     * @throws ValidationException When category is already first in the catalog
     * @throws DatabaseException When category position exchange fails
     * @throws InvalidArgumentException When category type does not match the collection
     * @throws LogicException When categories collection is not configured
     */
    private function handleMoveUp(CategoryMoveUpActionDTO $dto): void
    {
        if (!isset(Hilos::$db->categories[$dto->id])) {
            throw new ItemNotFoundForUpdateException('This category no longer exists');
        }

        $category = Hilos::$db->categories[$dto->id];
        $category->actions->moveUp();
        $this->setActionSuccessMessage(sprintf('Category “%s” moved up.', $category->name));
    }

    /**
     * Hands the uploaded picture of a new category to the library, which publishes it and acknowledges
     * the action once the files door answers.
     *
     * Deferred only for a tracked action: an untracked one owes no ack, and deferring it would leave the
     * dispatcher silent for a caller that never correlates.
     *
     * @param string $acceptKey Accept key of the connection the upload belongs to
     * @param CategoryPicturePublishActionDTO $dto Publish action payload
     * @throws LogicException When the files door is missing
     * @throws FeatureNotDeclaredException When the project keeps no files or takes no uploads
     * @throws InvalidFormatException When the upload id is one the wire does not allow
     * @throws InvalidArgumentException When the publish signal cannot be named or queued
     */
    private function handlePublish(string $acceptKey, CategoryPicturePublishActionDTO $dto): void
    {
        $this->agent->publishPicture($acceptKey, $dto->clientUploadId, $this->currentActionRequestId());
        if ($this->currentActionRequestId() !== null) {
            $this->deferActionReply();
        }
    }

    /**
     * Adds a category at the end of the catalog with a picture the person adding it has just published;
     * the library's own actions trim and check the name and the description.
     *
     * A refusal of the framework's tree goes on unchanged, so the person reads its sentence in the usual
     * action error. Anything else the create transaction may raise is wrapped in one named failure here,
     * where the wide contract is called, rather than widened onto the page's action contract; the person
     * sees the same placeholder either way.
     *
     * @param string $acceptKey Accept key of the acting connection
     * @param CategoryCreateActionDTO $dto Create action payload
     * @throws ValidationException When the picture is not a fresh file of the person adding the category
     * @throws EmptyValueException When the name or the description is empty
     * @throws ValueTooLongException When the name or the description is longer than its column
     * @throws DuplicateValueException When another category already has this name
     * @throws LogicException When another category already shows the picture, the files door is missing,
     *     or the create transaction raised something outside the framework's tree
     * @throws CreateNotAllowedException When this process may not add a category or its address
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading, the insert or the commit fails
     * @throws HilosException When the registry cannot be read, or the right, the database or the collection refuses the write
     */
    private function handleCreate(string $acceptKey, CategoryCreateActionDTO $dto): void
    {
        $fileId = CatalogPictureFile::fresh($acceptKey, $dto->pictureFileId);
        try {
            $category = Hilos::$db->categories->actions->create($dto->name, $dto->description, $fileId);
        } catch (HilosException $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw new LogicException('The category could not be added', previous: $failure);
        }
        $this->setActionSuccessMessage(sprintf('Category “%s” added.', $category->name));
    }
}
