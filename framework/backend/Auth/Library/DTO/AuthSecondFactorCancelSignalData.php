<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Auth\Flow\AuthFlowOutcome;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Users library -> the session holder: let this browser's second-factor wait go (HIL-494).
 *
 * Three endings send it: the person went back to sign in another way, the wait ran out under
 * a submit, and a removal was asked for from the code step. The holder
 * ({@see AbstractSessionsLibraryAgent}) releases the wait, sends the browser's other tabs back
 * to the address field and answers the submit - with the outcome the frame carries, or with
 * the address field when it carries none.
 */
final class AuthSecondFactorCancelSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $sessionToken Session cookie token of the browser
     * @param string $acceptKey Accept key of the connection that submitted
     * @param ?string $requestId Request id of the submit waiting on the answer, or null when nobody waits
     * @param ?string $action Action name the answer is for, or null when nobody waits
     * @param ?array<string, mixed> $outcome Reply to give ({@see AuthFlowOutcome::toArray()}), or null for the address field
     * @param ?string $code Why the other tabs go back (an AuthFlowOutcome::CODE_* value), or null when they were not refused
     */
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $acceptKey,
        public readonly ?string $requestId = null,
        public readonly ?string $action = null,
        public readonly ?array $outcome = null,
        public readonly ?string $code = null,
    ) {
    }

    /**
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            'sessionToken' => $this->sessionToken,
            'acceptKey' => $this->acceptKey,
            'requestId' => $this->requestId,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'code' => $this->code,
        ];
    }

    /**
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws InvalidFormatException When the payload names no session or no connection
     */
    public static function fromArray(array $data): static
    {
        return new static(
            sessionToken: self::requireString($data, 'sessionToken'),
            acceptKey: self::requireString($data, 'acceptKey'),
            requestId: self::optionalString($data, 'requestId'),
            action: self::optionalString($data, 'action'),
            outcome: self::optionalArray($data, 'outcome'),
            code: self::optionalString($data, 'code'),
        );
    }
}
