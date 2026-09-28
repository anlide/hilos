<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

use Hilos\Constants\SignalPayloadConstants;

/**
 * Writes the secret fields of an action payload as a mask before analytics stores it.
 *
 * One rule for every path an action payload reaches analytics by: a declared key is looked
 * for at the top level and, when the payload carries a `data` array, one level inside it. That
 * covers the flat action frame, the `data` wrapper some `fromArray()` readers accept, and the
 * envelope a worker hands an agent, where the action lies under `data`. A null or empty value
 * stays as it is, so the record still tells which way of confirming was used; any other value,
 * scalar or array, becomes {@see self::MASK}. A key the payload does not carry is not added.
 */
final class SecretPayloadMask
{
    public const string MASK = '***';

    /**
     * @param array<string, mixed> $payload Action payload as analytics receives it
     * @param list<string> $secretFields Payload keys the action DTO declares secret
     * @return array<string, mixed> The payload with every declared, filled key masked
     */
    public static function apply(array $payload, array $secretFields): array
    {
        $masked = self::maskLevel($payload, $secretFields);
        $data = $masked[SignalPayloadConstants::FIELD_DATA] ?? null;
        if (is_array($data)) {
            $masked[SignalPayloadConstants::FIELD_DATA] = self::maskLevel($data, $secretFields);
        }

        return $masked;
    }

    /**
     * @param array<string, mixed> $level One level of the payload
     * @param list<string> $secretFields Payload keys to mask
     * @return array<string, mixed> The level with its declared, filled keys masked
     */
    private static function maskLevel(array $level, array $secretFields): array
    {
        foreach ($secretFields as $field) {
            if (!array_key_exists($field, $level) || $level[$field] === null || $level[$field] === '') {
                continue;
            }

            $level[$field] = self::MASK;
        }

        return $level;
    }
}
