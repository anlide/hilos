<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CategoryMoveUpActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\DatabaseException;

/**
 * The shop's categories under the head of its administration section: the page
 * carries the categories table and its own move-up action.
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
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_CATEGORIES,
    ];

    /**
     * Routes category move up action to typed handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Action reply DTO, or null
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws ItemNotFoundForUpdateException When category no longer exists
     * @throws ValidationException When category is already first in the catalog
     * @throws DatabaseException When category position exchange fails
     * @throws InvalidArgumentException When category type does not match the collection
     * @throws LogicException When categories collection is not configured
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
}
