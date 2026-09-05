<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Sessions library → Hilos users page: what became of the takeover (HIL-824).
 *
 * What {@see HilosSignalConstants::HILOS_IMPERSONATE_DONE} carries, and the second half of the
 * two-step admin action: the page handed the work over and deferred its own ack, so this frame
 * is the ack. It travels back with the accept key and the request id it was given, which is
 * what makes the answer land on the one submit that asked.
 *
 * One outcome of two, told apart by the refusal: a sentence means the session was not rebound
 * and says why, and null means it was. The refusal is a string rather than a throw because the
 * guards run here, outside a page, where the dispatcher's exception hook does not reach.
 */
final class ImpersonateDoneSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Accept key of the admin who asked
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param ?string $error Why the takeover was refused, or null when it happened
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
