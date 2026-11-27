<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** An available locale and its optional country. */
final class AvailableLocaleRow extends BaseDTO
{
    public const string id = 'id';
    public const string code = 'code';
    public const string countryCode = 'countryCode';
    public const string countryName = 'countryName';

    /**
     * @param int $id Locale primary ID
     * @param string $code Locale code as stored
     * @param ?string $countryCode Country code as stored, or null for a countryless locale
     * @param ?string $countryName Base interface-language country name or its code, or null for a countryless locale
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly ?string $countryCode,
        public readonly ?string $countryName,
    ) {
    }

    /** @return array<string, mixed> Locale wire fields */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::code => $this->code,
            self::countryCode => $this->countryCode,
            self::countryName => $this->countryName,
        ];
    }

    /**
     * @param array<string, mixed> $data Locale wire fields
     * @return static Decoded locale row
     * @throws InvalidFormatException When a locale field is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::id),
            self::requireString($data, self::code),
            self::requireNullableString($data, self::countryCode),
            self::requireNullableString($data, self::countryName),
        );
    }
}
