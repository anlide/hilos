<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Notifications library → deliveries page: what became of the retry (HIL-771).
 *
 * What {@see HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE} carries, and the second half of
 * the two-step admin action: the page handed the work over and deferred its own ack, so this
 * frame is the ack. It travels back with the accept key and the request id it was given, which
 * is what makes the answer land on the one submit that asked.
 *
 * One outcome of two, told apart by the refusal: a sentence means the row was not re-queued and
 * says why, and null means it was.
 */
final class DeliveryRetryDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Accept key of the admin who asked
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param ?string $error Why the delivery was not re-queued, or null when it was
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
        public readonly ?string $error = null,
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
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'error' => $this->error,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            error: self::optionalString($data, 'error'),
        );
    }
}
