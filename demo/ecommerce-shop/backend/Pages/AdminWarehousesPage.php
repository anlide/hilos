<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\WarehouseCreateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * The shop's warehouses under the head of its administration section: the page
 * carries the warehouses table and its own add action.
 *
 * @property WarehousesLibraryAgent $agent
 */
final class AdminWarehousesPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_WAREHOUSES;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the warehouses library agent, which owns warehouses and neither users
     * nor connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::WAREHOUSES_LIBRARY;

    public const array ACTIONS = [
        EcommerceShopSignalConstants::WAREHOUSE_CREATE => WarehouseCreateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES,
    ];

    /**
     * Routes the warehouse create action to its typed handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Action reply DTO, or null
     * @throws AgentUnknownActionException When action is not supported by this page
     * @throws InvalidActionPayloadException When action payload does not match the action name
     * @throws EmptyValueException When the name or the address is empty
     * @throws ValueTooLongException When the name or the address is longer than its column
     * @throws DuplicateValueException When another warehouse already has this name
     * @throws CreateNotAllowedException When this process may not add a warehouse
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::WAREHOUSE_CREATE:
                if (!$dto instanceof WarehouseCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, WarehouseCreateActionDTO::class, $dto);
                }
                $this->handleCreate($dto);

                return null;

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
        }
    }

    /**
     * Adds a warehouse through the library's own actions, which trim and check the name and the address.
     *
     * @param WarehouseCreateActionDTO $dto Create action payload
     * @throws EmptyValueException When the name or the address is empty
     * @throws ValueTooLongException When the name or the address is longer than its column
     * @throws DuplicateValueException When another warehouse already has this name
     * @throws CreateNotAllowedException When this process may not add a warehouse
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or the insert fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the write
     */
    private function handleCreate(WarehouseCreateActionDTO $dto): void
    {
        $warehouse = Hilos::$db->warehouses->actions->create($dto->name, $dto->address);
        $this->setActionSuccessMessage(sprintf('Warehouse “%s” added.', $warehouse->name));
    }
}
