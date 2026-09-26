<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Hilos\Constants\HttpConstants;

/**
 * HttpHeaderHelper - case-insensitive HTTP request header reads.
 *
 * RFC 7230 treats header field names as case-insensitive. Header maps parsed
 * from the wire carry lowercase names; this helper also tolerates
 * non-normalized maps handed to public boundaries (e.g. HttpRouter::route()).
 * It also builds the one response header value whose shape is not a single
 * token: Content-Disposition with a name ({@see contentDisposition()}).
 *
 * @package Hilos\Utils\Helpers
 */
class HttpHeaderHelper
{
    /** @var string Name a download is offered under when its own name leaves nothing printable */
    private const string FALLBACK_FILENAME = 'file';

    /** @var string Character standing in the ASCII name for every character outside printable ASCII */
    private const string NON_ASCII_REPLACEMENT = '_';

    /** @var string A character outside printable ASCII, read as UTF-8 */
    private const string NON_ASCII_CHARACTER = '/[^\x20-\x7E]/u';

    /** @var string A byte outside printable ASCII, for a name that is not valid UTF-8 */
    private const string NON_ASCII_BYTE = '/[^\x20-\x7E]/';

    /** @var list<string> Characters a header value may not carry: they would end the header or the line */
    private const array HEADER_BREAKING_CHARACTERS = ["\r", "\n"];

    /** @var list<string> Characters the quoted ASCII name may not carry: they would end or escape the quotes */
    private const array QUOTE_BREAKING_CHARACTERS = ['"', '\\'];

    /**
     * Read a header value by case-insensitive header name.
     *
     * Fast path is a direct lowercase-key hit; on miss the map is scanned
     * with case-insensitive name comparison.
     *
     * @param array<string, mixed> $headers Header name to value map
     * @param string $name Header name in any case (e.g. User-Agent)
     * @return ?string Header value, or null when absent or not a string
     */
    public static function get(array $headers, string $name): ?string
    {
        $lowerName = strtolower($name);

        $direct = $headers[$lowerName] ?? null;
        if (is_string($direct)) {
            return $direct;
        }

        foreach ($headers as $headerName => $value) {
            if (is_string($value) && strtolower((string)$headerName) === $lowerName) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Parse the Cookie request header into a name => value map.
     *
     * @param array<string, mixed> $headers Header name to value map
     * @return array<string, string> Cookie name to value pairs, empty when the Cookie header is absent
     */
    public static function parseCookies(array $headers): array
    {
        $cookies = [];

        // Cookie header format: "name1=value1; name2=value2".
        // external-boundary: the Cookie header comes from the client, which may send it empty
        $cookieHeader = self::get($headers, HttpConstants::HEADER_COOKIE) ?? '';
        if ($cookieHeader === '') {
            return $cookies;
        }

        foreach (explode(';', $cookieHeader) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies;
    }

    /**
     * Builds a Content-Disposition value naming a file both ways a browser reads a name.
     *
     * The quoted `filename` is the ASCII fallback: the base name with the characters that would
     * break the header or its quotes taken out and every character outside printable ASCII
     * standing as `_`. The `filename*` is the same base name in UTF-8, percent-encoded
     * (RFC 6266), which a current browser prefers - so a Cyrillic name survives the download
     * instead of arriving mangled.
     *
     * @param string $type Disposition type, {@see HttpConstants::CONTENT_DISPOSITION_INLINE} or
     *     {@see HttpConstants::CONTENT_DISPOSITION_ATTACHMENT}
     * @param string $filename Name the file was uploaded under
     * @return string Header value
     */
    public static function contentDisposition(string $type, string $filename): string
    {
        $name = basename(str_replace(self::HEADER_BREAKING_CHARACTERS, '', $filename));
        if ($name === '') {
            $name = self::FALLBACK_FILENAME;
        }

        $quotable = str_replace(self::QUOTE_BREAKING_CHARACTERS, '', $name);
        $ascii = preg_replace(self::NON_ASCII_CHARACTER, self::NON_ASCII_REPLACEMENT, $quotable)
            ?? preg_replace(self::NON_ASCII_BYTE, self::NON_ASCII_REPLACEMENT, $quotable)
            ?? self::FALLBACK_FILENAME;
        if ($ascii === '') {
            $ascii = self::FALLBACK_FILENAME;
        }

        return "{$type}; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($name);
    }
}
