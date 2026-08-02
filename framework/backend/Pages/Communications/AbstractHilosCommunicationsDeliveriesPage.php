<?php

declare(strict_types=1);

namespace Hilos\Pages\Communications;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;

/**
 * AbstractHilosCommunicationsDeliveriesPage - the admin channel delivery log (HIL-201).
 *
 * The read-only delivery journal ({@see \Hilos\Tables\Communications\HilosNotificationDeliveriesTable}
 * serves its windows) plus the single row action it owns: retry. A failed delivery
 * row can be re-queued — its status resets to pending with zero attempts and the
 * channel's deliver signal is sent again — so an admin can recover a delivery that
 * failed on a transient outage without asking the user to re-trigger the event. The
 * action is valid only for a failed row (pending is already queued; sent is done).
 *
 * The page guards its subscription and the retry action with a flagless AUTHENTICATED
 * guard (a signed-in-only admin surface). Projects add a concrete subclass with a
 * `SUBSCRIPTION_AGENT_TYPE`; they add no action code of their own.
 */
abstract class AbstractHilosCommunicationsDeliveriesPage extends AbstractHilosPage
{
    public const string PAGE = HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES;

    public const array ACTIONS = [
        HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY => HilosDeliveryRetryActionDTO::class,
    ];

    public const array AUTH_ACTIONS = [
        HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_DELIVERIES,
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED,
            ],
        ],
    ];

    /**
     * Routes the delivery retry action to its typed handler.
     *
     * @param string $acceptKey WebSocket accept key for the client
     * @param string $action Action name from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @throws AgentUnknownActionException When the action is not supported by this page
     * @throws InvalidActionPayloadException When the action payload does not match the action name
     * @throws TableActionException When the delivery is unknown or not in a retryable state
     * @throws DatabaseException When the delivery lookup or re-queue fails
     * @return ?ActionReplyDTO Domain reply for a tracked action, or null when the action answers with nothing
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY:
                if (!$dto instanceof HilosDeliveryRetryActionDTO) {
                    throw new InvalidActionPayloadException($action, HilosDeliveryRetryActionDTO::class, $dto);
                }
                $this->handleRetry($dto);

                break;

            default:
                throw new AgentUnknownActionException("Unknown action: {$action}");
        }

        return null;
    }

    /**
     * Re-queues a failed delivery: validates the row, then delegates the reset and re-send.
     *
     * @param HilosDeliveryRetryActionDTO $dto Retry action payload
     * @throws TableActionException When the delivery is unknown, not failed, or notifications are unavailable
     * @throws DatabaseException When the delivery lookup fails
     */
    private function handleRetry(HilosDeliveryRetryActionDTO $dto): void
    {
        $delivery = $this->deliveries()->findById($dto->deliveryId);
        if ($delivery === null) {
            throw new TableActionException("Unknown delivery: {$dto->deliveryId}");
        }

        if ($delivery->status !== DeliveryStatus::FAILED) {
            throw new TableActionException('Only failed deliveries can be retried');
        }

        $notify = Hilos::$notify;
        if ($notify === null) {
            throw new TableActionException('Notifications are not available');
        }

        $notify->retryDelivery($delivery);
    }

    /**
     * Resolves the framework-owned notification-deliveries object collection.
     *
     * @return ObjectNotificationDeliveries Delivery persistence primitives
     * @throws TableActionException When the deliveries object collection is not configured
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        $collection = Hilos::$db?->getObjectCollection(HilosDbContext::notificationDeliveries);
        if (!$collection instanceof ObjectNotificationDeliveries) {
            throw new TableActionException('Notification deliveries object collection is not configured');
        }

        return $collection;
    }
}
