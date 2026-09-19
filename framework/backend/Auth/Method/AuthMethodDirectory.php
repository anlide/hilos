<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Hilos;

/**
 * AuthMethodDirectory - the sign-in methods a project has wired (HIL-427).
 *
 * The code half of the method set: what a project CAN offer, because a handler stands
 * behind each key. What an installation DOES offer is this list narrowed by one setting
 * ({@see AuthMethodSettings::DISABLED_KEY}), and {@see EnabledAuthMethods} is the one
 * place that narrows it. An administrator can only take methods away from this list,
 * never add one to it: a key with no handler behind it would draw a button whose submit
 * the backend refuses.
 *
 * The framework ships this empty base; a project points {@see Hilos::AUTH_METHOD_DIRECTORY}
 * at its own subclass and names its methods by overriding {@see methods()}. The order is
 * the order of the sign-in buttons, and the admin screen lists the methods in it too.
 */
abstract class AuthMethodDirectory
{
    /**
     * The method keys this project has wired, in the order a surface shows them.
     *
     * A project overrides this, and a project with OAuth providers appends the keys of
     * its provider directory, whose order is the order of their icons:
     *
     * ```php
     * protected static function methods(): array
     * {
     *     return [
     *         ...parent::methods(),
     *         AuthMethodKey::PASSWORD,
     *         AuthMethodKey::PASSKEY,
     *         ...array_keys(Hilos::oauthProviderDirectoryClass()::all()),
     *     ];
     * }
     * ```
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    protected static function methods(): array
    {
        return [];
    }

    /**
     * Returns every wired method key once, in the order a surface shows them.
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    public static function keys(): array
    {
        return array_values(array_unique(static::methods()));
    }

    /**
     * Whether the project has wired a method under this key.
     *
     * @param string $methodKey Method key (see AuthMethodKey)
     * @return bool True when the key is one of {@see keys()}
     */
    public static function has(string $methodKey): bool
    {
        return in_array($methodKey, static::keys(), true);
    }
}
