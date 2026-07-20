<?php

declare(strict_types=1);

namespace Hilos\Auth;

/**
 * PhoneNumber - normalizes a submitted phone number to canonical E.164 (HIL-280).
 *
 * The single place phone input is turned into the identifier stored on an `sms`
 * identity and used as the verification `identifier`. Both the request and the
 * confirm step normalize through here, so the same phone typed with different
 * spacing/separators maps to one identity and one challenge. This is a syntactic
 * normalizer, not a carrier/region validator: it accepts the E.164 shape (an
 * optional leading `+` and 8–15 digits) and returns the digits with a canonical
 * leading `+`. Anything outside that shape is rejected as null so the caller can
 * answer generically without disclosing why.
 */
final class PhoneNumber
{
    /**
     * Minimum digit count (excluding the `+`) accepted as an E.164 number.
     */
    private const int MIN_DIGITS = 8;

    /**
     * Maximum digit count (excluding the `+`) allowed by E.164.
     */
    private const int MAX_DIGITS = 15;

    /**
     * Normalizes a raw phone string to canonical E.164, or null when malformed.
     *
     * Common separators (spaces, dashes, dots, parentheses) are stripped and a
     * leading international `00` prefix is folded to `+`. The result is the digits
     * with a single leading `+`; a value that is not 8–15 digits (optionally
     * `+`-prefixed) returns null.
     *
     * @param string $raw Submitted phone number in any common formatting
     * @return ?string Canonical E.164 (`+` followed by 8–15 digits), or null when invalid
     */
    public static function normalize(string $raw): ?string
    {
        $stripped = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';
        if ($stripped === '') {
            return null;
        }

        // Fold a leading international "00" trunk prefix to the canonical "+".
        if (str_starts_with($stripped, '00')) {
            $stripped = '+' . substr($stripped, 2);
        }

        $digits = str_starts_with($stripped, '+') ? substr($stripped, 1) : $stripped;
        if (preg_match('/^\d{' . self::MIN_DIGITS . ',' . self::MAX_DIGITS . '}$/', $digits) !== 1) {
            return null;
        }

        return '+' . $digits;
    }
}
