<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** A successful provider exchange reports metadata for the captured identity; nobody waits for an answer. */
final class UserIdentityProviderNameWriteSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string identityId = 'identityId';
    public const string providerName = 'providerName';

    /**
     * @param int $userId Person who owns the captured identity
     * @param int $identityId Persisted identity captured during account resolution
     * @param string $providerName Personal name reported by the provider
     * @throws InvalidFormatException When either id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $identityId,
        public readonly string $providerName,
    ) {
        if ($userId <= 0 || $identityId <= 0) {
            throw new InvalidFormatException('User and identity ids must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Restored provider metadata report
     * @throws InvalidFormatException When a field is absent or malformed, or an id is not positive
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            identityId: self::requireInt($data, self::identityId),
            providerName: self::requireString($data, self::providerName),
        );
    }

    /** @return array{userId: int, identityId: int, providerName: string} Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::identityId => $this->identityId,
            self::providerName => $this->providerName,
        ];
    }
}
