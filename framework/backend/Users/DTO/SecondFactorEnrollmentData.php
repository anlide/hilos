<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** Committed enrollment material returned only to the requesting connection. */
final class SecondFactorEnrollmentData extends BaseDTO
{
    public const string authenticatorId = 'authenticatorId';
    public const string secret = 'secret';
    public const string otpauthUri = 'otpauthUri';

    /**
     * @param int $authenticatorId Committed authenticator id
     * @param string $secret Base32 secret
     * @param string $otpauthUri Authenticator application address
     * @throws InvalidFormatException When the authenticator id is not positive
     */
    public function __construct(
        public readonly int $authenticatorId,
        public readonly string $secret,
        public readonly string $otpauthUri,
    ) {
        if ($authenticatorId <= 0) {
            throw new InvalidFormatException('Authenticator id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Enrollment material
     * @throws InvalidFormatException When a field is missing or invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::authenticatorId),
            self::requireString($data, self::secret),
            self::requireString($data, self::otpauthUri),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [self::authenticatorId => $this->authenticatorId, self::secret => $this->secret, self::otpauthUri => $this->otpauthUri];
    }
}
