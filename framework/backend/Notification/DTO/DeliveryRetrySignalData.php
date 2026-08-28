<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Pages\Communications\AbstractHilosCommunicationsDeliveriesPage;

/**
 * Deliveries page → notifications library: re-queue this delivery (HIL-771).
 *
 * What {@see HilosSignalConstants::HILOS_DELIVERY_RETRY} carries. The admin surface keeps the
 * action and the ADMIN level closing it ({@see AbstractHilosCommunicationsDeliveriesPage}),
 * because an agent action has no such level to inherit; the delivery journal is owned by
 * {@see AbstractNotificationsLibraryAgent}, so the row named here is reset and re-dispatched
 * there.
 *
 * The delivery is named by id and judged nowhere else: whether it exists and whether it is
 * failed are questions for the process that owns the row, at the moment it writes.
 *
 * The accept key and the request id are the admin waiting, not part of the retry: they travel
 * whole so the page can answer the one connection that asked, on the one request it made. The
 * request id is nullable because an untracked submit correlates nothing - such a caller is
 * told nothing back, exactly as it was before the move.
 */
final class DeliveryRetrySignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $deliveryId Delivery journal row to reset and re-dispatch
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'deliveryId' => $this->deliveryId,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no delivery or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            deliveryId: self::requireInt($data, 'deliveryId'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
        );
    }
}
