<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Session\HilosSessionHostInterface;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library → session holder: this browser walked away from its registration.
 *
 * Drops the session's pending registration and the waits standing on it
 * ({@see HilosSessionHostInterface::abandonRegistration()}). The reservation of the
 * identifier is deliberately NOT released: it is what keeps a second person from taking the
 * address while the first is still deciding, and it expires on its own.
 */
final class AuthRegistrationAbandonedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session token abandoning its registration
     * @param string $initiatorAcceptKey Accept key of the connection that abandoned it
     * @param ?string $requestId Request id of the action that caused this, or null when it was untracked
     * @param ?string $action Action name to answer, or null when nothing is waiting on an answer
     * @param ?array<string, mixed> $outcome Reply the answer carries ({@see AuthFlowOutcome::toArray()}), or null
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $initiatorAcceptKey,
        public readonly ?string $requestId = null,
        public readonly ?string $action = null,
        public readonly ?array $outcome = null,
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
            'sessionToken' => $this->sessionToken,
            'initiatorAcceptKey' => $this->initiatorAcceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no session or no connection to answer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, 'sessionToken'),
            initiatorAcceptKey: self::requireString($data, 'initiatorAcceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
            outcome: self::optionalArray($data, 'outcome'),
        );
    }
}
