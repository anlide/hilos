<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\I18n\Catalog\BuiltInI18nCatalog;

/** Built-in country codes delivered in catalog order in the page scope. */
final readonly class BuiltInCountryCodes
{
    public const string DATA = 'builtInCountryCodes';

    /** @param list<string> $codes Codes in catalog order */
    public function __construct(public array $codes)
    {
    }

    /** @return self Built-in country codes */
    public static function fromCatalog(): self
    {
        $codes = [];
        foreach (BuiltInI18nCatalog::countries() as $country) {
            $codes[] = $country->code;
        }

        return new self($codes);
    }

    /** @return list<string> Country codes in catalog order */
    public function toArray(): array
    {
        return $this->codes;
    }
}
