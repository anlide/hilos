<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\WarehouseCreateActionDTO;
use Demo\EcommerceShop\Pages\DTO\WarehouseUpdateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * The shop's warehouses under the head of its administration section: the page
 * carries the warehouses table and its own add and edit actions.
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
        EcommerceShopSignalConstants::WAREHOUSE_UPDATE => WarehouseUpdateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_WAREHOUSES,
    ];

    /**
     * Routes the warehouse create and update actions to their typed handlers.
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
     * @throws ItemNotFoundForUpdateException When the edited warehouse no longer exists
     * @throws ValidationException When the edited warehouse changed since the editor opened it
     * @throws WriteNotAllowedException When this process may not write the warehouse
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws LogicException When the warehouses collection is not configured
     * @throws InvalidArgumentException When a loaded warehouse has the wrong type
     * @throws DatabaseException When collection loading, the insert or the update fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store or update announcement raises
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

            case EcommerceShopSignalConstants::WAREHOUSE_UPDATE:
                if (!$dto instanceof WarehouseUpdateActionDTO) {
                    throw new InvalidActionPayloadException($action, WarehouseUpdateActionDTO::class, $dto);
                }
                $this->handleUpdate($dto);

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

    /**
     * Edits a warehouse while it still holds the values the editor opened with; the warehouse
     * actions trim and check the new name and address. The check and the write run in one
     * handler of the writing process, so no other write lands between them.
     *
     * @param WarehouseUpdateActionDTO $dto Update action payload
     * @throws ItemNotFoundForUpdateException When the warehouse no longer exists
     * @throws ValidationException When the warehouse changed since the editor opened it
     * @throws EmptyValueException When the name or the address is empty
     * @throws ValueTooLongException When the name or the address is longer than its column
     * @throws DuplicateValueException When another warehouse already has this name
     * @throws WriteNotAllowedException When this process may not write the warehouse
     * @throws LogicException When the warehouses collection is not configured
     * @throws InvalidArgumentException When a loaded warehouse has the wrong type
     * @throws DatabaseException When loading the warehouse or the update fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws HilosException When the right, the database or the announcement refuses the save
     */
    private function handleUpdate(WarehouseUpdateActionDTO $dto): void
    {
        if (!isset(Hilos::$db->warehouses[$dto->id])) {
            throw new ItemNotFoundForUpdateException('This warehouse no longer exists');
        }

        $warehouse = Hilos::$db->warehouses[$dto->id];
        if ($warehouse->name !== $dto->expectedName || $warehouse->address !== $dto->expectedAddress) {
            throw new ValidationException('The warehouse changed elsewhere. Review the current values and save again.');
        }
        $warehouse->actions->update($dto->name, $dto->address);
        $this->setActionSuccessMessage(sprintf('Warehouse “%s” saved.', $warehouse->name));
    }
}
