<?php

declare(strict_types=1);

namespace Hilos\I18n;

/**
 * BrowserLanguages - the one place that says what a browser's request for languages means (HIL-2309).
 *
 * A browser names the languages it wants in `Accept-Language` (RFC 9110 §12.5.4): ranges separated by
 * commas, each with an optional weight `;q=` from 0 to 1 with at most three decimals. The request is read
 * into tags of the reference's shape, by weight from the highest, the header's order among equal weights:
 * the language is the first subtag, two or three Latin letters, lower-cased; the region is the two-letter
 * subtag right after it, or after a four-letter script subtag, upper-cased; everything else - the script,
 * extensions, a numeric region such as `es-419` - is dropped (`zh-Hant-TW` → `zh-TW`, `EN-us` → `en-US`).
 *
 * Never refuses anything: an item whose weight cannot be read, whose language is not two or three letters,
 * the wildcard `*` and a weight of zero are simply absent, and a missing or wholly broken header reads as
 * an empty request. A tag repeated after its reading keeps its first place.
 *
 * A language is its first subtag taken literally: there is no table of synonymous codes (`nb` is not `no`).
 */
final class BrowserLanguages
{
    /**
     * @var int Most tags a request keeps; the rest of the list is dropped. The header is written by the
     *     browser while the list travels with the tab's row across the node, and live browsers send fewer
     *     than ten.
     */
    public const int MAX_TAGS = 16;

    /** @var string Separator of the items of the header and of the stored list */
    private const string ITEM_SEPARATOR = ',';

    /** @var string Separator of the subtags of a language tag */
    private const string SUBTAG_SEPARATOR = '-';

    /** @var float Weight of an item that names none (RFC 9110 §12.4.2) */
    private const float DEFAULT_WEIGHT = 1.0;

    /** @var string One item of the header: a range and an optional weight of at most three decimals */
    private const string ITEM_PATTERN = '/^(?<range>[^;\s]+)(?:\s*;\s*q=(?<weight>0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?$/i';

    /** @var string Range that stands for any language */
    private const string WILDCARD = '*';

    /** @var string First subtag of a tag the reference can know: two or three Latin letters */
    private const string LANGUAGE_PATTERN = '/^[a-z]{2,3}$/i';

    /** @var string Subtag of a region the reference can know: two Latin letters */
    private const string REGION_PATTERN = '/^[a-z]{2}$/i';

    /** @var string Subtag of a script, which may stand between the language and the region */
    private const string SCRIPT_PATTERN = '/^[a-z]{4}$/i';

    /**
     * @param list<string> $tags Tags of the reference's shape, by weight from the highest, without repeats
     */
    private function __construct(public readonly array $tags)
    {
    }

    /**
     * Reads the browser's header.
     *
     * @param ?string $header Raw value of `Accept-Language`, or null when the browser sent none
     * @return self The tags the header names, empty when it names none that can be read
     */
    public static function fromAcceptLanguage(?string $header): self
    {
        if ($header === null) {
            return new self([]);
        }

        $candidates = [];
        $weights = [];
        foreach (explode(self::ITEM_SEPARATOR, $header) as $item) {
            if (preg_match(self::ITEM_PATTERN, trim($item), $match) !== 1 || $match['range'] === self::WILDCARD) {
                continue;
            }
            $weight = isset($match['weight']) ? (float)$match['weight'] : self::DEFAULT_WEIGHT;
            $tag = self::tagOf($match['range']);
            if ($weight === 0.0 || $tag === null) {
                continue;
            }
            $weights[] = $weight;
            $candidates[] = $tag;
        }
        // The sort is stable: among equal weights the header's order stays.
        arsort($weights, SORT_NUMERIC);

        $ranked = [];
        foreach (array_keys($weights) as $index) {
            $ranked[] = $candidates[$index];
        }

        return new self(array_slice(array_values(array_unique($ranked)), 0, self::MAX_TAGS));
    }

    /**
     * Reads the list a tab's row keeps. The stored shape is the header without its weights, so it is read
     * the same way.
     *
     * @param ?string $stored The stored list, or null when the row keeps none
     * @return self The tags the list names
     */
    public static function fromStored(?string $stored): self
    {
        return self::fromAcceptLanguage($stored);
    }

    /**
     * @return ?string The tags joined in their order, for a tab's row; null when there are none
     */
    public function toStored(): ?string
    {
        return $this->tags === [] ? null : implode(self::ITEM_SEPARATOR, $this->tags);
    }

    /**
     * @return list<string> Codes of the languages the tags name, in the tags' order, each once (`ru-UA`, `ru`, `uk` → `ru`, `uk`)
     */
    public function languages(): array
    {
        $languages = [];
        foreach ($this->tags as $tag) {
            $languages[] = explode(self::SUBTAG_SEPARATOR, $tag)[0];
        }

        return array_values(array_unique($languages));
    }

    /**
     * @param string $languageCode Code of a language of the reference
     * @return list<string> Tags of that language with a region, in their order (`ru` of `ru-UA`, `ru`, `ru-RU` → `ru-UA`, `ru-RU`)
     */
    public function variantsOf(string $languageCode): array
    {
        $prefix = $languageCode . self::SUBTAG_SEPARATOR;
        $variants = [];
        foreach ($this->tags as $tag) {
            if (str_starts_with($tag, $prefix)) {
                $variants[] = $tag;
            }
        }

        return $variants;
    }

    /**
     * @param string $range Language range of one item of the header
     * @return ?string The tag of the reference's shape, or null when the range names no language it can know
     */
    private static function tagOf(string $range): ?string
    {
        $subtags = explode(self::SUBTAG_SEPARATOR, $range);
        if (preg_match(self::LANGUAGE_PATTERN, $subtags[0]) !== 1) {
            return null;
        }
        $language = strtolower($subtags[0]);
        $regionAt = isset($subtags[1]) && preg_match(self::SCRIPT_PATTERN, $subtags[1]) === 1 ? 2 : 1;
        if (!isset($subtags[$regionAt]) || preg_match(self::REGION_PATTERN, $subtags[$regionAt]) !== 1) {
            return $language;
        }

        return $language . self::SUBTAG_SEPARATOR . strtoupper($subtags[$regionAt]);
    }
}
