<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/**
 * ChannelConfigValidators - reusable validators for channel config fields (HIL-200).
 *
 * A {@see ChannelConfigField} references one of these as its validator (a first-class
 * callable). The admin channel-set action runs the validator on the type-coerced value
 * and rejects the write with the returned phrase, so a bad port, an unknown security
 * mode, or a malformed from-address never reaches persistence. Each validator returns
 * null for a valid value (including an empty value, which means "clear the override,
 * inherit env/default") and a caller-facing domain phrase otherwise.
 */
final class ChannelConfigValidators
{
    /** Lowest valid TCP port. */
    private const int PORT_MIN = 1;

    /** Highest valid TCP port. */
    private const int PORT_MAX = 65535;

    /**
     * Validates a TCP port is within 1..65535.
     *
     * @param bool|float|int|string $value Coerced field value
     * @return ?string Error phrase, or null when valid
     */
    public static function port(bool|float|int|string $value): ?string
    {
        $port = (int) $value;

        return $port >= self::PORT_MIN && $port <= self::PORT_MAX
            ? null
            : 'Port must be between ' . self::PORT_MIN . ' and ' . self::PORT_MAX;
    }

    /**
     * Validates a mail transport security mode is one of the accepted values.
     *
     * @param bool|float|int|string $value Coerced field value
     * @return ?string Error phrase, or null when valid
     */
    public static function mailSecurity(bool|float|int|string $value): ?string
    {
        $allowed = ['starttls', 'tls', 'none'];

        return in_array((string) $value, $allowed, true)
            ? null
            : 'SMTP security must be one of: ' . implode(', ', $allowed);
    }

    /**
     * Validates an email address, allowing an empty value (inherit env/default).
     *
     * @param bool|float|int|string $value Coerced field value
     * @return ?string Error phrase, or null when valid or empty
     */
    public static function emailAddress(bool|float|int|string $value): ?string
    {
        $address = (string) $value;

        return $address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) !== false
            ? null
            : 'Must be a valid email address';
    }

    /**
     * Validates an HTTP method is one accepted for an SMS gateway request (HIL-285).
     *
     * @param bool|float|int|string $value Coerced field value
     * @return ?string Error phrase, or null when valid
     */
    public static function httpMethod(bool|float|int|string $value): ?string
    {
        $allowed = ['GET', 'POST'];

        return in_array(strtoupper((string) $value), $allowed, true)
            ? null
            : 'HTTP method must be one of: ' . implode(', ', $allowed);
    }

    /**
     * Validates an SMS gateway auth mode is one of the accepted values (HIL-285).
     *
     * @param bool|float|int|string $value Coerced field value
     * @return ?string Error phrase, or null when valid
     */
    public static function smsAuthMode(bool|float|int|string $value): ?string
    {
        $allowed = ['none', 'query', 'header', 'basic'];

        return in_array((string) $value, $allowed, true)
            ? null
            : 'Auth mode must be one of: ' . implode(', ', $allowed);
    }

    /**
     * Validates an SMS field map is a JSON object of string entries (HIL-285).
     *
     * @param bool|float|int|string $value Coerced field value
     * @return ?string Error phrase, or null when valid or empty
     */
    public static function jsonObject(bool|float|int|string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? null : 'Must be a valid JSON object';
    }
}
