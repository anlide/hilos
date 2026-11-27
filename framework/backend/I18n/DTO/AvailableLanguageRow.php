<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** An available language with its available locale variants. */
final class AvailableLanguageRow extends BaseDTO
{
    public const string id = 'id';
    public const string code = 'code';
    public const string nativeName = 'nativeName';
    public const string locales = 'locales';

    /**
     * @param int $id Language primary ID
     * @param string $code Language code as stored
     * @param string $nativeName Language name in that language
     * @param list<AvailableLocaleRow> $locales Available locale variants in display order
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $nativeName,
        public readonly array $locales,
    ) {
    }

    /** @return array<string, mixed> Language wire fields */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::code => $this->code,
            self::nativeName => $this->nativeName,
            self::locales => array_map(static fn (AvailableLocaleRow $row): array => $row->toArray(), $this->locales),
        ];
    }

    /**
     * @param array<string, mixed> $data Language wire fields
     * @return static Decoded language row
     * @throws InvalidFormatException When a language field or locale row is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        $locales = [];
        foreach (self::requireArray($data, self::locales) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Available language carries a locale row that is not an object');
            }
            $locales[] = AvailableLocaleRow::fromArray($row);
        }

        return new static(
            self::requireInt($data, self::id),
            self::requireString($data, self::code),
            self::requireString($data, self::nativeName),
            $locales,
        );
    }
}
