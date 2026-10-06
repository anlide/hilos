<?php

declare(strict_types=1);

namespace Hilos\I18n\Catalog;

use JsonException;

/** Canonical SHA-256 digest of the five built-in i18n catalog maps. */
final class I18nCatalogFingerprint
{
    /**
     * Arrays are accepted only at this hashing boundary: callers keep the five
     * private catalog maps, while readers receive immutable definitions.
     * The group names, recursively sorted keys and JSON encoding define the
     * persisted digest contract used by catalog reflow.
     *
     * @param array<string, array{native_name: string, rtl: bool}> $languages Language rows by code
     * @param array<string, array{currency_symbol: string, currency_code: string}> $countries Country rows by code
     * @param array<string, array<string, string>> $locales Locale format rows by canonical code
     * @param array<string, string> $defaultLocales Suggested locale codes by source country
     * @param array<string, array<string, string>> $countryNames Names by country and language code
     * @return string Lowercase, unprefixed 64-character SHA-256 digest
     * @throws JsonException When a catalog value cannot be encoded as JSON
     */
    public static function of(
        array $languages,
        array $countries,
        array $locales,
        array $defaultLocales,
        array $countryNames,
    ): string {
        $catalog = [
            'languages' => $languages,
            'countries' => $countries,
            'locales' => $locales,
            'default_locales' => $defaultLocales,
            'country_names' => $countryNames,
        ];

        return hash('sha256', json_encode(
            self::sortedMaps($catalog),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<array-key, mixed> $map Nested catalog data
     * @return array<array-key, mixed> Recursively sorted associative maps
     */
    private static function sortedMaps(array $map): array
    {
        foreach ($map as $key => $value) {
            if (is_array($value)) {
                $map[$key] = self::sortedMaps($value);
            }
        }
        if (!array_is_list($map)) {
            ksort($map, SORT_STRING);
        }

        return $map;
    }
}
