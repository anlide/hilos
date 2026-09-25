<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Proof submitted for one protected operation (HIL-495).
 */
final class StepUpConfirmActionDTO extends ActionPayloadDTO
{
    public const string operation = 'operation';
    public const string method = 'method';
    public const string code = 'code';
    public const string backupCode = 'backupCode';
    public const string password = 'password';
    public const string passkey = 'passkey';

    /**
     * @param string $operation Protected operation key
     * @param string $method Method returned by the opening action
     * @param string $code App, backup, email or SMS code; empty when another method is used
     * @param bool $backupCode Whether a second-factor code is a backup code
     * @param string $password Password proof; empty when another method is used
     * @param ?StepUpPasskeyAnswer $passkey WebAuthn assertion, or null for another method
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $method,
        public readonly string $code,
        public readonly bool $backupCode,
        public readonly string $password,
        public readonly ?StepUpPasskeyAnswer $passkey,
    ) {
    }

    /**
     * @return string Step-up confirm action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STEP_UP_CONFIRM;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed payload
     * @throws InvalidFormatException When a required field is absent or mistyped
     */
    public static function fromArray(array $data): static
    {
        $passkey = self::requireNullableArray($data, self::passkey);

        return new static(
            operation: trim(self::requireString($data, self::operation)),
            method: self::requireString($data, self::method),
            code: trim(self::requireString($data, self::code)),
            backupCode: self::requireBool($data, self::backupCode),
            password: self::requireString($data, self::password),
            passkey: $passkey === null ? null : StepUpPasskeyAnswer::fromArray($passkey),
        );
    }

    /**
     * @return array<string, mixed> Action payload with every proof field present
     */
    public function toArray(): array
    {
        return [
            self::operation => $this->operation,
            self::method => $this->method,
            self::code => $this->code,
            self::backupCode => $this->backupCode,
            self::password => $this->password,
            self::passkey => $this->passkey?->toArray(),
        ];
    }
}
