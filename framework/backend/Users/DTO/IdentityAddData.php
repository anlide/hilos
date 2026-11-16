<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\IdentityType;

/** Verified material for one ordinary addition to an existing person's ways in. */
abstract class IdentityAddData extends BaseDTO
{
    public const string type = 'type';

    /**
     * @param array<string, mixed> $data Tagged transport payload
     * @return static Concrete identity material
     * @throws InvalidFormatException When the tag or its material is invalid
     */
    public static function fromArray(array $data): static
    {
        return match (self::requireString($data, self::type)) {
            IdentityType::PASSWORD => PasswordIdentityAddData::fromArray($data),
            IdentityType::SMS => SmsIdentityAddData::fromArray($data),
            IdentityType::OAUTH => OAuthIdentityAddData::fromArray($data),
            IdentityType::PASSKEY => PasskeyIdentityAddData::fromArray($data),
            default => throw new InvalidFormatException('Unknown identity addition type'),
        };
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @param string $type Expected tag
     * @throws InvalidFormatException When the tag does not name this material
     */
    protected static function requireType(array $data, string $type): void
    {
        if (self::requireString($data, self::type) !== $type) {
            throw new InvalidFormatException('Identity addition type does not match its material');
        }
    }
}
