<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row of the languages table (HIL-1474).
 *
 * One row is one language of the system, switched on or off. The row key is the language code
 * and rides the payload under {@see self::code}, never `id`: a slot carrying `id` is taken by
 * the frontend normalizer for an entity fragment.
 */
final class HilosI18nLanguagesTableRow extends AbstractTableRow
{
    public const string code = 'code';
    public const string nativeName = 'nativeName';
    public const string rtl = 'rtl';
    public const string enabled = 'enabled';
    public const string isDefault = 'isDefault';
    public const string isOwn = 'isOwn';

    /**
     * @param string $code Language code, which is also the row key
     * @param string $nativeName Name the language calls itself
     * @param bool $rtl Whether writing runs right to left
     * @param bool $enabled Whether the language is switched on
     * @param bool $isDefault Whether this is the installation default
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     */
    public function __construct(
        public string $code,
        public string $nativeName,
        public bool $rtl,
        public bool $enabled,
        public bool $isDefault,
        public bool $isOwn,
    ) {
    }

    /**
     * @return string Language code
     */
    public function getRowKey(): string
    {
        return $this->code;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::code;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::code => $this->code,
            self::nativeName => $this->nativeName,
            self::rtl => $this->rtl,
            self::enabled => $this->enabled,
            self::isDefault => $this->isDefault,
            self::isOwn => $this->isOwn,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed languages table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            code: self::requireString($data, self::code),
            nativeName: self::requireString($data, self::nativeName),
            rtl: self::requireBool($data, self::rtl),
            enabled: self::requireBool($data, self::enabled),
            isDefault: self::requireBool($data, self::isDefault),
            isOwn: self::requireBool($data, self::isOwn),
        );
    }
}
