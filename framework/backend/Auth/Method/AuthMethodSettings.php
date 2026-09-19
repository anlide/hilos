<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

/**
 * AuthMethodSettings - the one setting that narrows the wired sign-in methods (HIL-427).
 *
 * The set is stored as the list of methods an administrator turned OFF, and in one key
 * rather than one boolean per method as delivery channels are. That is not taste: "the
 * last method cannot be turned off" is a rule about the whole set, a setting rule sees
 * only the value it is about to write ({@see AuthMethodsDisabledRule}), and the rule has
 * to hold whichever door the value comes through - the sign-in methods screen, the general
 * settings table or a preset. Stored as the switched-off ones, the default is empty and a
 * method a project wires later arrives switched on by itself.
 *
 * The value is the keys joined by commas. Whitespace around a key is tolerated and order
 * carries no meaning; what the screen writes is in directory order.
 */
final class AuthMethodSettings
{
    /** Setting key holding the switched-off method keys. */
    public const string DISABLED_KEY = 'auth.methods.disabled';

    /** Separator between two keys in the stored value. */
    private const string SEPARATOR = ',';

    /**
     * Reads the stored value as a list of method keys.
     *
     * @param string $value Stored value, keys joined by commas
     * @return list<string> Distinct method keys in the order they were written, blanks dropped
     */
    public static function parse(string $value): array
    {
        $keys = [];
        foreach (explode(self::SEPARATOR, $value) as $part) {
            $key = trim($part);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Writes a list of method keys as the stored value.
     *
     * @param list<string> $keys Method keys to store
     * @return string Keys joined by commas, empty for none
     */
    public static function format(array $keys): string
    {
        return implode(self::SEPARATOR, $keys);
    }
}
