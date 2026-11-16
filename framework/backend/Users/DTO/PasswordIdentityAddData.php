<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\IdentityType;

/** Verified password identity material; no external proof is performed by its receiver. */
final class PasswordIdentityAddData extends IdentityAddData
{
    public const string identifier = 'identifier';
    public const string passwordHash = 'passwordHash';

    /**
     * @param string $identifier Verified identifier material
     * @param string $passwordHash Verified passwordHash material
     */
    public function __construct(
        public readonly string $identifier,
        public readonly string $passwordHash,
    ) {
    }

    /**
     * @param array<string, mixed> $data Tagged transport payload
     * @return static Verified identity material
     * @throws InvalidFormatException When a required field has the wrong type or the tag is foreign
     */
    public static function fromArray(array $data): static
    {
        self::requireType($data, IdentityType::PASSWORD);

        return new static(
            identifier: self::requireString($data, self::identifier),
            passwordHash: self::requireString($data, self::passwordHash),
        );
    }

    /** @return array<string, mixed> Tagged transport payload */
    public function toArray(): array
    {
        return [
            self::type => IdentityType::PASSWORD,
            self::identifier => $this->identifier,
            self::passwordHash => $this->passwordHash,
        ];
    }
}
