<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * One language, keyed by its decimal primary ID without an entity-fragment id field.
 *
 * {@see deleteDisabledReason} is the full sentence the screen shows when it explains why deletion
 * is off for the row; it says in words what {@see deleteReason} names by code, and is null when
 * deletion is allowed.
 */
final class HilosI18nLanguagesTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string languageId = 'languageId';
    public const string code = 'code';
    public const string nativeName = 'nativeName';
    public const string rtl = 'rtl';
    public const string enabled = 'enabled';
    public const string isDefault = 'isDefault';
    public const string isOwn = 'isOwn';
    public const string canDelete = 'canDelete';
    public const string deleteReason = 'deleteReason';
    public const string deleteDisabledReason = 'deleteDisabledReason';

    /**
     * @param int $languageId Persisted language ID
     * @param string $code Language code
     * @param string $nativeName Name the language calls itself
     * @param bool $rtl Whether writing runs right to left
     * @param bool $enabled Whether the language is switched on
     * @param bool $isDefault Whether this is the installation default
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param bool $canDelete Whether the current deletion verdict permits removal
     * @param ?string $deleteReason Default, locales or names; null when deletion is allowed
     * @param ?string $deleteDisabledReason Full sentence why deletion is off, or null when deletion is allowed
     * @throws InvalidFormatException When a row ID is not positive
     */
    public function __construct(
        public int $languageId,
        public string $code,
        public string $nativeName,
        public bool $rtl,
        public bool $enabled,
        public bool $isDefault,
        public bool $isOwn,
        public bool $canDelete,
        public ?string $deleteReason,
        public ?string $deleteDisabledReason,
    ) {
        if ($languageId <= 0) {
            throw new InvalidFormatException('Invalid language ID');
        }
    }

    /**
     * @return string Decimal language ID
     */
    public function getRowKey(): string
    {
        return (string) $this->languageId;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->getRowKey(),
            self::languageId => $this->languageId,
            self::code => $this->code,
            self::nativeName => $this->nativeName,
            self::rtl => $this->rtl,
            self::enabled => $this->enabled,
            self::isDefault => $this->isDefault,
            self::isOwn => $this->isOwn,
            self::canDelete => $this->canDelete,
            self::deleteReason => $this->deleteReason,
            self::deleteDisabledReason => $this->deleteDisabledReason,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed languages table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        if (self::requireString($data, self::rowKey) !== (string) self::requireInt($data, self::languageId)) {
            throw new InvalidFormatException('Row key does not match the language ID');
        }
        return new static(
            languageId: self::requireInt($data, self::languageId),
            code: self::requireString($data, self::code),
            nativeName: self::requireString($data, self::nativeName),
            rtl: self::requireBool($data, self::rtl),
            enabled: self::requireBool($data, self::enabled),
            isDefault: self::requireBool($data, self::isDefault),
            isOwn: self::requireBool($data, self::isOwn),
            canDelete: self::requireBool($data, self::canDelete),
            deleteReason: self::optionalString($data, self::deleteReason),
            deleteDisabledReason: self::optionalString($data, self::deleteDisabledReason),
        );
    }
}
