<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\DTO\CountryLocaleChoice;

/**
 * One country, keyed by its decimal primary ID without an entity-fragment id field.
 *
 * {@see deleteDisabledReason} is the full sentence the screen shows when it explains why deletion
 * is off for the row; it says in words what {@see deleteReason} names by code, and is null when
 * deletion is permitted.
 */
final class HilosI18nCountriesTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string countryId = 'countryId';
    public const string code = 'code';
    public const string name = 'name';
    public const string currencySymbol = 'currencySymbol';
    public const string currencyCode = 'currencyCode';
    public const string defaultLocaleId = 'defaultLocaleId';
    public const string defaultLocaleCode = 'defaultLocaleCode';
    public const string enabled = 'enabled';
    public const string isOwn = 'isOwn';
    public const string locales = 'locales';
    public const string catalogDefaultLocaleCode = 'catalogDefaultLocaleCode';
    public const string canDelete = 'canDelete';
    public const string deleteReason = 'deleteReason';
    public const string deleteDisabledReason = 'deleteDisabledReason';

    /**
     * @param int $countryId Persisted country ID
     * @param string $code Country code
     * @param ?string $name Non-empty base name in the default language, or null when none is written
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Three-letter currency code
     * @param ?int $defaultLocaleId Selected locale ID, or null
     * @param ?string $defaultLocaleCode Code of the country's default locale, or null when none is chosen
     * @param bool $enabled Whether the country is switched on
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param list<CountryLocaleChoice> $locales Serialization boundary: Every locale of the country, on or off, by code
     * @param ?string $catalogDefaultLocaleCode Built-in catalog suggestion, whether created or not
     * @param bool $canDelete Whether the country rule permits deletion
     * @param ?string $deleteReason Catalog or dependency refusal, or null when deletion is permitted
     * @param ?string $deleteDisabledReason Full sentence why deletion is off, or null when deletion is permitted
     * @throws InvalidFormatException When a row ID is not positive
     */
    public function __construct(
        public int $countryId,
        public string $code,
        public ?string $name,
        public string $currencySymbol,
        public string $currencyCode,
        public ?int $defaultLocaleId,
        public ?string $defaultLocaleCode,
        public bool $enabled,
        public bool $isOwn,
        public array $locales,
        public ?string $catalogDefaultLocaleCode,
        public bool $canDelete,
        public ?string $deleteReason,
        public ?string $deleteDisabledReason,
    ) {
        if ($countryId <= 0 || ($defaultLocaleId !== null && $defaultLocaleId <= 0)) {
            throw new InvalidFormatException('Invalid country ID');
        }
    }

    /**
     * @return string Decimal country ID
     */
    public function getRowKey(): string
    {
        return (string) $this->countryId;
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
            self::countryId => $this->countryId,
            self::code => $this->code,
            self::name => $this->name,
            self::currencySymbol => $this->currencySymbol,
            self::currencyCode => $this->currencyCode,
            self::defaultLocaleId => $this->defaultLocaleId,
            self::defaultLocaleCode => $this->defaultLocaleCode,
            self::enabled => $this->enabled,
            self::isOwn => $this->isOwn,
            self::locales => array_map(static fn (CountryLocaleChoice $locale): array => $locale->toArray(), $this->locales),
            self::catalogDefaultLocaleCode => $this->catalogDefaultLocaleCode,
            self::canDelete => $this->canDelete,
            self::deleteReason => $this->deleteReason,
            self::deleteDisabledReason => $this->deleteDisabledReason,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed countries table row
     * @throws InvalidFormatException When a required field is missing or the deletion verdict is invalid
     */
    public static function fromArray(array $data): static
    {
        if (self::requireString($data, self::rowKey) !== (string) self::requireInt($data, self::countryId)) {
            throw new InvalidFormatException('Row key does not match the country ID');
        }
        if (!array_key_exists(self::defaultLocaleId, $data)
            || ($data[self::defaultLocaleId] !== null
                && (!is_int($data[self::defaultLocaleId]) || $data[self::defaultLocaleId] <= 0))) {
            throw new InvalidFormatException('Invalid default locale ID');
        }
        $locales = [];
        foreach (self::requireArray($data, self::locales) as $locale) {
            if (!is_array($locale)) {
                throw new InvalidFormatException('Country locale choice must be an object');
            }
            $locales[] = new CountryLocaleChoice(
                self::requireInt($locale, CountryLocaleChoice::localeId),
                self::requireString($locale, CountryLocaleChoice::code),
                self::requireBool($locale, CountryLocaleChoice::enabled),
            );
        }
        if (!array_key_exists(self::deleteReason, $data)) {
            throw new InvalidFormatException('Missing country deleteReason');
        }
        if (!array_key_exists(self::deleteDisabledReason, $data)) {
            throw new InvalidFormatException('Missing country deleteDisabledReason');
        }
        $deleteReason = self::optionalString($data, self::deleteReason);
        if (!in_array($deleteReason, [
            null,
            CountryCardSummary::DELETE_KNOWN,
            CountryCardSummary::DELETE_LOCALES,
            CountryCardSummary::DELETE_NAMES,
        ], true)) {
            throw new InvalidFormatException('Invalid country deleteReason');
        }

        return new static(
            countryId: self::requireInt($data, self::countryId),
            code: self::requireString($data, self::code),
            name: self::optionalString($data, self::name),
            currencySymbol: self::requireString($data, self::currencySymbol),
            currencyCode: self::requireString($data, self::currencyCode),
            defaultLocaleId: $data[self::defaultLocaleId],
            defaultLocaleCode: self::optionalString($data, self::defaultLocaleCode),
            enabled: self::requireBool($data, self::enabled),
            isOwn: self::requireBool($data, self::isOwn),
            locales: $locales,
            catalogDefaultLocaleCode: self::optionalString($data, self::catalogDefaultLocaleCode),
            canDelete: self::requireBool($data, self::canDelete),
            deleteReason: $deleteReason,
            deleteDisabledReason: self::optionalString($data, self::deleteDisabledReason),
        );
    }
}
