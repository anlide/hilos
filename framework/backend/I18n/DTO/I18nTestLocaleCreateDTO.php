<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\I18nTestCommandConstants;

/** Typed command-channel request to create a locale fixture. */
final class I18nTestLocaleCreateDTO extends BaseDTO
{
    /**
     * @param int $languageId Persisted language ID
     * @param ?int $countryId Country ID, or null
     * @param bool $enabled Requested switch
     * @throws InvalidFormatException When a parent ID is not positive
     */
    public function __construct(
        public readonly int $languageId,
        public readonly ?int $countryId,
        public readonly bool $enabled,
    ) {
        if ($languageId <= 0 || ($countryId !== null && $countryId <= 0)) {
            throw new InvalidFormatException('Locale parent IDs must be positive integers');
        }
    }

    /**
     * @param array<string, mixed> $data Command payload
     * @return static Typed creation request with the command's optional defaults
     * @throws InvalidFormatException When a field is absent or has the wrong type
     */
    public static function fromArray(array $data): static
    {
        $data += [
            I18nTestCommandConstants::FIELD_COUNTRY_ID => null,
            I18nTestCommandConstants::FIELD_ENABLED => false,
        ];

        return new static(
            self::requireInt($data, I18nTestCommandConstants::FIELD_LANGUAGE_ID),
            self::optionalInt($data, I18nTestCommandConstants::FIELD_COUNTRY_ID),
            self::requireBool($data, I18nTestCommandConstants::FIELD_ENABLED),
        );
    }

    /** @return array<string, mixed> Command payload */
    public function toArray(): array
    {
        return [
            I18nTestCommandConstants::FIELD_LANGUAGE_ID => $this->languageId,
            I18nTestCommandConstants::FIELD_COUNTRY_ID => $this->countryId,
            I18nTestCommandConstants::FIELD_ENABLED => $this->enabled,
        ];
    }
}
