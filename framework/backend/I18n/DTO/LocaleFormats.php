<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\View\Item\Locale;
use Hilos\I18n\Catalog\LocaleDefinition;
use Hilos\I18n\MeasurementSystem;

/** Seven display formats shared by locale actions and table rows. */
final class LocaleFormats extends BaseDTO
{
    public const string date = 'date';
    public const string time = 'time';
    public const string number = 'number';
    public const string phone = 'phone';
    public const string address = 'address';
    public const string measurement = 'measurement';
    public const string collation = 'collation';

    /**
     * @param string $date Date template
     * @param string $time Time template
     * @param string $number Number template
     * @param string $phone Phone template
     * @param string $address Address template
     * @param MeasurementSystem $measurement Unit system
     * @param string $collation Sorting template
     */
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        public readonly string $number,
        public readonly string $phone,
        public readonly string $address,
        public readonly MeasurementSystem $measurement,
        public readonly string $collation,
    ) {
    }

    /**
     * @param array<string, mixed> $data Wire format values
     * @return static Parsed format values
     * @throws InvalidFormatException When a field is absent, has the wrong type, or names an unknown unit system
     */
    public static function fromArray(array $data): static
    {
        $measurement = self::requireString($data, self::measurement);

        return new static(
            self::requireString($data, self::date),
            self::requireString($data, self::time),
            self::requireString($data, self::number),
            self::requireString($data, self::phone),
            self::requireString($data, self::address),
            MeasurementSystem::tryFrom($measurement)
                ?? throw new InvalidFormatException('Unknown measurement system: ' . $measurement),
            self::requireString($data, self::collation),
        );
    }

    /** @return array<string, string> Wire format values */
    public function toArray(): array
    {
        return [
            self::date => $this->date,
            self::time => $this->time,
            self::number => $this->number,
            self::phone => $this->phone,
            self::address => $this->address,
            self::measurement => $this->measurement->value,
            self::collation => $this->collation,
        ];
    }

    /**
     * @param Locale $locale Stored locale
     * @return static Its seven display formats
     */
    public static function ofLocale(Locale $locale): static
    {
        return new static(
            $locale->dateFormat,
            $locale->timeFormat,
            $locale->numberFormat,
            $locale->phoneFormat,
            $locale->addressFormat,
            $locale->measurementSystem,
            $locale->collation,
        );
    }

    /**
     * @param LocaleDefinition $definition Built-in locale
     * @return static Its seven display formats
     */
    public static function ofCatalog(LocaleDefinition $definition): static
    {
        return new static(
            $definition->dateFormat,
            $definition->timeFormat,
            $definition->numberFormat,
            $definition->phoneFormat,
            $definition->addressFormat,
            MeasurementSystem::from($definition->measurementSystem),
            $definition->collation,
        );
    }
}
