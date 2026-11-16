<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\IdentityType;

/** Verified sms identity material; no external proof is performed by its receiver. */
final class SmsIdentityAddData extends IdentityAddData
{
    public const string identifier = 'identifier';

    /**
     * @param string $identifier Verified identifier material
     */
    public function __construct(
        public readonly string $identifier,
    ) {
    }

    /**
     * @param array<string, mixed> $data Tagged transport payload
     * @return static Verified identity material
     * @throws InvalidFormatException When a required field has the wrong type or the tag is foreign
     */
    public static function fromArray(array $data): static
    {
        self::requireType($data, IdentityType::SMS);

        return new static(
            identifier: self::requireString($data, self::identifier),
        );
    }

    /** @return array<string, mixed> Tagged transport payload */
    public function toArray(): array
    {
        return [
            self::type => IdentityType::SMS,
            self::identifier => $this->identifier,
        ];
    }
}
