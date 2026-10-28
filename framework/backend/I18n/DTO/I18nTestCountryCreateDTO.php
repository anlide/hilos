<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\I18nTestCommandConstants;

/** Typed command-channel request to create a country fixture. */
final class I18nTestCountryCreateDTO extends BaseDTO
{
    /**
     * @param string $code Country code
     * @param string $currencySymbol Currency symbol
     * @param string $currencyCode Currency code
     * @param bool $enabled Requested switch
     */
    public function __construct(
        public readonly string $code,
        public readonly string $currencySymbol,
        public readonly string $currencyCode,
        public readonly bool $enabled,
    ) {
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Typed creation request with the command's optional defaults
     * @throws InvalidFormatException When a field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $data += [
            I18nTestCommandConstants::FIELD_ENABLED => false,
        ];

        return new static(
            self::requireString($data, I18nTestCommandConstants::FIELD_CODE),
            self::requireString($data, I18nTestCommandConstants::FIELD_CURRENCY_SYMBOL),
            self::requireString($data, I18nTestCommandConstants::FIELD_CURRENCY_CODE),
            self::requireBool($data, I18nTestCommandConstants::FIELD_ENABLED),
        );
    }

    /** @return array<string, mixed> Command payload */
    public function toArray(): array
    {
        return [
            I18nTestCommandConstants::FIELD_CODE => $this->code,
            I18nTestCommandConstants::FIELD_CURRENCY_SYMBOL => $this->currencySymbol,
            I18nTestCommandConstants::FIELD_CURRENCY_CODE => $this->currencyCode,
            I18nTestCommandConstants::FIELD_ENABLED => $this->enabled,
        ];
    }
}
