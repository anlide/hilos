<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\I18n\I18nTestCommandConstants;

/** Typed command-channel request to create a language fixture. */
final class I18nTestLanguageCreateDTO extends BaseDTO
{
    /**
     * @param string $code Language code
     * @param string $nativeName Native name
     * @param bool $rtl Writing direction
     * @param bool $enabled Requested switch
     */
    public function __construct(
        public readonly string $code,
        public readonly string $nativeName,
        public readonly bool $rtl,
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
            I18nTestCommandConstants::FIELD_RTL => false,
            I18nTestCommandConstants::FIELD_ENABLED => false,
        ];

        return new static(
            self::requireString($data, I18nTestCommandConstants::FIELD_CODE),
            self::requireString($data, I18nTestCommandConstants::FIELD_NATIVE_NAME),
            self::requireBool($data, I18nTestCommandConstants::FIELD_RTL),
            self::requireBool($data, I18nTestCommandConstants::FIELD_ENABLED),
        );
    }

    /** @return array<string, mixed> Command payload */
    public function toArray(): array
    {
        return [
            I18nTestCommandConstants::FIELD_CODE => $this->code,
            I18nTestCommandConstants::FIELD_NATIVE_NAME => $this->nativeName,
            I18nTestCommandConstants::FIELD_RTL => $this->rtl,
            I18nTestCommandConstants::FIELD_ENABLED => $this->enabled,
        ];
    }
}
