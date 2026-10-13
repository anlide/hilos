<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\DTO\CountryLocaleChoice;

/**
 * Backend row of the countries table (HIL-1475).
 *
 * One row is one country of the system, switched on or off. The row key is the country code
 * and rides the payload under {@see self::code}, never `id`: a slot carrying `id` is taken by
 * the frontend normalizer for an entity fragment. An absent base name and an absent default
 * locale stay null here; the view is what draws the code and the "Not chosen" badge.
 */
final class HilosI18nCountriesTableRow extends AbstractTableRow
{
    public const string code = 'code';
    public const string name = 'name';
    public const string currencySymbol = 'currencySymbol';
    public const string currencyCode = 'currencyCode';
    public const string defaultLocaleCode = 'defaultLocaleCode';
    public const string enabled = 'enabled';
    public const string isOwn = 'isOwn';
    public const string locales = 'locales';
    public const string catalogDefaultLocaleCode = 'catalogDefaultLocaleCode';
    public const string canDelete = 'canDelete';
    public const string deleteReason = 'deleteReason';

    /**
     * @param string $code Country code, which is also the row key
     * @param ?string $name Non-empty base name in the default language, or null when none is written
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Three-letter currency code
     * @param ?string $defaultLocaleCode Code of the country's default locale, or null when none is chosen
     * @param bool $enabled Whether the country is switched on
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param list<CountryLocaleChoice> $locales Every locale of the country, on or off, by code
     * @param ?string $catalogDefaultLocaleCode Built-in catalog suggestion, whether created or not
     * @param bool $canDelete Whether the current summary permits deletion
     * @param ?string $deleteReason Catalog or dependency refusal, or null when deletion is permitted
     */
    public function __construct(
        public string $code,
        public ?string $name,
        public string $currencySymbol,
        public string $currencyCode,
        public ?string $defaultLocaleCode,
        public bool $enabled,
        public bool $isOwn,
        public array $locales,
        public ?string $catalogDefaultLocaleCode,
        public bool $canDelete,
        public ?string $deleteReason,
    ) {
    }

    /**
     * @return string Country code
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
            self::name => $this->name,
            self::currencySymbol => $this->currencySymbol,
            self::currencyCode => $this->currencyCode,
            self::defaultLocaleCode => $this->defaultLocaleCode,
            self::enabled => $this->enabled,
            self::isOwn => $this->isOwn,
            self::locales => array_map(static fn (CountryLocaleChoice $locale): array => $locale->toArray(), $this->locales),
            self::catalogDefaultLocaleCode => $this->catalogDefaultLocaleCode,
            self::canDelete => $this->canDelete,
            self::deleteReason => $this->deleteReason,
        ];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed countries table row
     * @throws InvalidFormatException When a required field is missing or the deletion verdict is invalid
     */
    public static function fromArray(array $data): static
    {
        $locales = [];
        foreach (self::requireArray($data, self::locales) as $locale) {
            if (!is_array($locale)) {
                throw new InvalidFormatException('Country locale choice must be an object');
            }
            $locales[] = new CountryLocaleChoice(
                self::requireString($locale, CountryLocaleChoice::code),
                self::requireBool($locale, CountryLocaleChoice::enabled),
            );
        }
        if (!array_key_exists(self::deleteReason, $data)) {
            throw new InvalidFormatException('Missing country deleteReason');
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
            code: self::requireString($data, self::code),
            name: self::optionalString($data, self::name),
            currencySymbol: self::requireString($data, self::currencySymbol),
            currencyCode: self::requireString($data, self::currencyCode),
            defaultLocaleCode: self::optionalString($data, self::defaultLocaleCode),
            enabled: self::requireBool($data, self::enabled),
            isOwn: self::requireBool($data, self::isOwn),
            locales: $locales,
            catalogDefaultLocaleCode: self::optionalString($data, self::catalogDefaultLocaleCode),
            canDelete: self::requireBool($data, self::canDelete),
            deleteReason: $deleteReason,
        );
    }
}
