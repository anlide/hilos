<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions;

use Hilos\Core\Exception\LogicException;

/**
 * CatalogSlug - the address given to a section or an item of the shop's catalog.
 *
 * Built from the name: lowercase, every run of characters other than letters, marks and digits
 * folded into one hyphen, no hyphen at either end. There is no transliteration, so a name in
 * any script keeps its own letters. An address once given is never given to anybody else, so
 * a name whose address is taken gets the first free numbered one: `roses`, `roses-2`,
 * `roses-3`. The addresses are compared as strings, byte for byte, the way the binary
 * collation of the slug columns compares them.
 */
final class CatalogSlug
{
    /** Length of the slug columns, in characters. */
    public const int MAX_LENGTH = 120;

    /** Characters an address keeps of the name, leaving the rest of the column to the number. */
    private const int BASE_MAX_LENGTH = 100;

    private const string SEPARATOR = '-';
    private const string NOT_KEPT = '/[^\p{L}\p{M}\p{N}]+/u';
    private const int FIRST_NUMBER = 2;

    /**
     * Builds the address of a name, before any number is added.
     *
     * @param string $name Name as saved
     * @param string $fallback Address of a name with no letter or digit in it
     * @return string The address, at most 100 characters long
     * @throws LogicException When the pattern cannot be applied to the name
     */
    public static function fromName(string $name, string $fallback): string
    {
        $folded = preg_replace(self::NOT_KEPT, self::SEPARATOR, mb_strtolower($name))
            ?? throw new LogicException('The address of a name cannot be built: ' . preg_last_error_msg());
        $base = trim(mb_substr(trim($folded, self::SEPARATOR), 0, self::BASE_MAX_LENGTH), self::SEPARATOR);

        return $base === '' ? $fallback : $base;
    }

    /**
     * Picks the first address of the series no other record has ever held.
     *
     * The series is the base itself, then the base with -2, -3 and so on. An address is free when
     * nobody holds it or when it is the record's own already; an address out of use belongs to
     * nobody and is never given again.
     *
     * @param string $base Address built by {@see self::fromName()}
     * @param array<string, ?int> $holders Every address ever given, to the id of its record, or to null once it is out of use
     * @param ?int $recordId Id of the record the address is for, null for a record not saved yet
     * @return string The address to give
     */
    public static function pick(string $base, array $holders, ?int $recordId): string
    {
        $candidate = $base;
        for ($number = self::FIRST_NUMBER; !self::isFree($candidate, $holders, $recordId); $number++) {
            $candidate = $base . self::SEPARATOR . $number;
        }

        return $candidate;
    }

    /**
     * @param string $candidate Address being tried
     * @param array<string, ?int> $holders Every address ever given, to the id of its record or to null
     * @param ?int $recordId Id of the record the address is for, null for a record not saved yet
     * @return bool True when nobody holds the address or the record holds it already
     */
    private static function isFree(string $candidate, array $holders, ?int $recordId): bool
    {
        if (!array_key_exists($candidate, $holders)) {
            return true;
        }

        return $recordId !== null && $holders[$candidate] === $recordId;
    }
}
