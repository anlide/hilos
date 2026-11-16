<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Identity\IdentityType;

/** Verified oauth identity material; no external proof is performed by its receiver. */
final class OAuthIdentityAddData extends IdentityAddData
{
    public const string provider = 'provider';
    public const string subject = 'subject';
    public const string providerName = 'providerName';

    /**
     * @param string $provider Verified provider material
     * @param string $subject Verified subject material
     * @param ?string $providerName Verified providerName material
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $providerName,
    ) {
    }

    /**
     * @param array<string, mixed> $data Tagged transport payload
     * @return static Verified identity material
     * @throws InvalidFormatException When a required field has the wrong type or the tag is foreign
     */
    public static function fromArray(array $data): static
    {
        self::requireType($data, IdentityType::OAUTH);

        return new static(
            provider: self::requireString($data, self::provider),
            subject: self::requireString($data, self::subject),
            providerName: self::requireNullableString($data, self::providerName),
        );
    }

    /** @return array<string, mixed> Tagged transport payload */
    public function toArray(): array
    {
        return [
            self::type => IdentityType::OAUTH,
            self::provider => $this->provider,
            self::subject => $this->subject,
            self::providerName => $this->providerName,
        ];
    }
}
