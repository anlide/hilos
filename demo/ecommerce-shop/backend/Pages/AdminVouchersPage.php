<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\VoucherCreateActionDTO;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
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
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * The shop's vouchers table and add action under the head of its administration section.
 *
 * @property VouchersLibraryAgent $agent
 */
final class AdminVouchersPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_VOUCHERS;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the vouchers library agent, which owns the vouchers and their addresses
     * and neither users nor connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::VOUCHERS_LIBRARY;

    public const array ACTIONS = [
        EcommerceShopSignalConstants::VOUCHER_CREATE => VoucherCreateActionDTO::class,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_VOUCHERS,
    ];

    /**
     * Dispatches the voucher create action in the vouchers library.
     *
     * @param string $acceptKey WebSocket connection sending the action
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO Null for the standard tracked success reply
     * @throws AgentUnknownActionException When this page does not own the action
     * @throws InvalidActionPayloadException When the DTO does not match the action
     * @throws EmptyValueException When the voucher code is empty
     * @throws ValueTooLongException When the voucher code is longer than its column
     * @throws ValidationException When the code or percentage is invalid
     * @throws DuplicateValueException When another voucher already has the code
     * @throws CreateNotAllowedException When this process may not add a voucher
     * @throws ExternalWriterException When the collection is configured for an external writer
     * @throws WriteNotAllowedException When the write guard refuses the save
     * @throws CallbackNotSetException When the collection cannot wrap the new object
     * @throws DatabaseException When the insert or collection loading fails
     * @throws SourceChangeSubscriberException When a subscriber to the write announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException When the object lacks a usable primary key
     * @throws DuplicateIdException When the collection already holds the assigned key
     * @throws TableNameUndeterminedException When the collection table cannot be determined
     * @throws LogicException When the collection entity is unavailable for reload
     * @throws HilosException When the collection refuses a reload after database replacement
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::VOUCHER_CREATE:
                if (!$dto instanceof VoucherCreateActionDTO) {
                    throw new InvalidActionPayloadException($action, VoucherCreateActionDTO::class, $dto);
                }
                $this->handleCreate($dto);

                return null;

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
        }
    }

    /**
     * Adds a voucher through the library's collection action.
     *
     * @param VoucherCreateActionDTO $dto Create action payload
     * @throws EmptyValueException When the voucher code is empty
     * @throws ValueTooLongException When the voucher code is longer than its column
     * @throws ValidationException When the code or percentage is invalid
     * @throws DuplicateValueException When another voucher already has the code
     * @throws CreateNotAllowedException When this process may not add a voucher
     * @throws ExternalWriterException When the collection is configured for an external writer
     * @throws WriteNotAllowedException When the write guard refuses the save
     * @throws CallbackNotSetException When the collection cannot wrap the new object
     * @throws DatabaseException When the insert or collection loading fails
     * @throws SourceChangeSubscriberException When a subscriber to the write announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException When the object lacks a usable primary key
     * @throws DuplicateIdException When the collection already holds the assigned key
     * @throws TableNameUndeterminedException When the collection table cannot be determined
     * @throws LogicException When the collection entity is unavailable for reload
     * @throws HilosException When the collection refuses a reload after database replacement
     */
    private function handleCreate(VoucherCreateActionDTO $dto): void
    {
        $voucher = Hilos::$db->vouchers->actions->create(
            $dto->code,
            VoucherFields::discountPercentFromInput($dto->discountPercent),
            $dto->active,
        );
        $this->setActionSuccessMessage(sprintf('Voucher “%s” added.', $voucher->code));
    }
}
