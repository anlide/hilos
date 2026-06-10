<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

/**
 * HttpHeaderHelper - case-insensitive HTTP request header reads.
 *
 * RFC 7230 treats header field names as case-insensitive. Header maps parsed
 * from the wire carry lowercase names; this helper also tolerates
 * non-normalized maps handed to public boundaries (e.g. HttpRouter::route()).
 *
 * @package Hilos\Utils\Helpers
 */
class HttpHeaderHelper
{
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
}
