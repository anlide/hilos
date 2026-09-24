<?php

declare(strict_types=1);

namespace Hilos\Auth\Library\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * ConfirmSecondFactorActionDTO - DTO for the code step of a sign-in held on its second factor (HIL-494).
 *
 * The code is either one from an authenticator app or a backup code, and the browser says
 * which: the two are checked against different things, and the flag spares the server a guess
 * on a string that could be read either way. Whether to trust this browser from now on rides
 * along, because the trust is written by the sign-in the code lets through.
 */
final class ConfirmSecondFactorActionDTO extends ActionPayloadDTO
{
    /**
     * @param string $code Code as typed (trimmed)
     * @param bool $backupCode Whether the code is a backup code rather than one from an app
     * @param bool $trustDevice Whether the browser asked not to be asked again
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $backupCode,
        public readonly bool $trustDevice,
    ) {
    }

    /**
     * Get action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR;
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     * @throws InvalidFormatException When a field the action needs is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            code: trim(self::requireString($data, 'code')),
            backupCode: self::requireBool($data, 'backupCode'),
            trustDevice: self::requireBool($data, 'trustDevice'),
        );
    }

    /**
     * Convert to array for transport.
     *
     * @return array{code: string, backupCode: bool, trustDevice: bool} Payload
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'backupCode' => $this->backupCode,
            'trustDevice' => $this->trustDevice,
        ];
    }
}
