<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/** Full available-language list for a group join or a catalog change. */
final class AvailableLanguagesSignalData extends BaseDTO implements SignalDataInterface
{
    public const string languages = 'languages';

    /** @param list<AvailableLanguageRow> $languages Available languages in code order */
    public function __construct(public readonly array $languages)
    {
    }

    /** @return array<string, mixed> Available languages as wire rows */
    public function toArray(): array
    {
        return [
            self::languages => array_map(static fn (AvailableLanguageRow $row): array => $row->toArray(), $this->languages),
        ];
    }

    /**
     * @param array<string, mixed> $data Available-language wire fields
     * @return static Decoded full list
     * @throws InvalidFormatException When the language list or a language row is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        $languages = [];
        foreach (self::requireArray($data, self::languages) as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('Available languages payload carries a language row that is not an object');
            }
            $languages[] = AvailableLanguageRow::fromArray($row);
        }

        return new static($languages);
    }
}
