<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Registration\RegistrationReservationService;
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
 *
 * The same subclass says whether the project registers anybody at all ({@see registers()},
 * HIL-2185): a project that only hands accounts out closes registration here, beside the
 * methods its accounts sign in with.
 */
abstract class AuthMethodDirectory
{
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

    /**
     * Whether a person may open an account in this project by themselves (see {@see registers()}).
     *
     * @return bool True when registration is open, false when the project declared it closed
     */
    public static function opensRegistration(): bool
    {
        return static::registers();
    }

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
     * Whether this project lets a person open an account by themselves.
     *
     * A project whose accounts are only ever handed out - by a command, by an administrator -
     * overrides this with false:
     *
     * ```php
     * protected static function registers(): bool
     * {
     *     return false;
     * }
     * ```
     *
     * False closes every road of registration: the identifier lookup answers a free address
     * with "registration is closed" and offers nothing to register with
     * ({@see IdentifierDetector}), every action that only opens an account is refused, whether
     * or not the project wired its method ({@see AuthMethodGate}), and the magic link and the
     * phone code, whose one letter both signs in and registers, are refused where they would
     * hold a free address or number ({@see RegistrationReservationService::hold()}). Both
     * endings that mint an account refuse it too: the landing as a backstop, and the ending
     * without a hold ({@see AbstractLibraryCommands::landAccountWithoutHold()}) as the one
     * lock on a provider's first sign-in, which holds nothing - wired or merely declared in
     * the provider directory. Signing in to an account that exists is not touched, and neither
     * are a password reset and a password added on the profile. The start still refuses a
     * directory that wires an SMS code or a provider beside a closed registration: the early
     * word, so the project hears of it at start rather than from a stranger's refused sign-in.
     *
     * @return bool True when registration is open, which is the framework's default
     */
    protected static function registers(): bool
    {
        return true;
    }
}
