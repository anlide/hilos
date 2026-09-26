<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * AccountDeletionStartActionDTO - DTO for starting an account deletion (HIL-302).
 *
 * Carries the code the account's address received. An account no code can reach starts
 * without one and sends an empty string, which the command does not read.
 */
final class AccountDeletionStartActionDTO extends ActionPayloadDTO
{
    public const string code = 'code';

    /**
     * @param string $code Code the account's address received, or empty when no code was sent
     */
    public function __construct(public readonly string $code)
    {
    }

    /**
     * @return string Account deletion start action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_ACCOUNT_DELETION_START;
    }

    /**
     * @param array<string, mixed> $data Action payload
     * @return static Parsed payload
     * @throws InvalidFormatException When the code field is absent or not a string
     */
    public static function fromArray(array $data): static
    {
        return new static(trim(self::requireString($data, self::code)));
    }

    /**
     * @return array{code: string} Action payload
     */
    public function toArray(): array
    {
        return [self::code => $this->code];
    }
}
