<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
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
 * Everything but the delivery id is the admin waiting, not part of the retry
 * ({@see HandoverAskInterface}): whom to answer and on which request, the action the ack is
 * addressed to, and the name the answer travels under. No sentence rides with it - the re-queued row
 * returns over the journal's next window - so the success message is null from the page that
 * asks today.
 */
final class DeliveryRetrySignalData extends BaseDTO implements HandoverAskInterface
{
    /**
     * @param int $deliveryId Delivery journal row to reset and re-dispatch
     * @param string $replySignal Agent-signal name the library reports back under
     * @param string $acceptKey Initiating connection accept key to answer
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     */
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
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
            'replySignal' => $this->replySignal,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'successMessage' => $this->successMessage,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no delivery, nobody to answer or no action to answer on
     */
    public static function fromArray(array $data): static
    {
        return new static(
            deliveryId: self::requireInt($data, 'deliveryId'),
            replySignal: self::requireString($data, 'replySignal'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::requireString($data, 'action'),
            successMessage: self::optionalString($data, 'successMessage'),
        );
    }
}
