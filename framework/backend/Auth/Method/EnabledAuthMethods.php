<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * EnabledAuthMethods - the sign-in methods this installation offers right now (HIL-427).
 *
 * The wired methods ({@see AuthMethodDirectory}) minus the ones an administrator switched
 * off ({@see AuthMethodSettings::DISABLED_KEY}), in directory order. Everything that asks
 * "is this way in open" asks here: identifier detection, the server-side lock on the
 * sign-in actions ({@see AuthMethodGate}), and the set the handshake and the settings
 * library hand to every surface.
 *
 * Enabled is the administrator's decision and nothing more: a method switched on that the
 * installation cannot serve (a provider without its client pair) is still enabled here, and
 * {@see keys()} and {@see isEnabled()} still name it - the gate and the detector read them. The
 * set on the wire ({@see toWire()}) says of each entry whether it is ready
 * ({@see AuthMethodReadiness}, HIL-1080), and a sign-in surface narrows it to the ready ones in
 * the frontend core, because the switches of the sign-in methods screen read that same set and
 * must go on showing an unready method as on.
 *
 * Nothing is cached. Settings are a collection every process reads locally, so the set is
 * read again on every call and a switch takes effect on the next action on every node,
 * without anything having to be told to forget what it held.
 *
 * A switched-off key the directory no longer names is skipped: the value may predate the
 * code, and a method that is not wired is not offered whatever the setting says. A project
 * whose settings catalog does not carry the key has switched nothing off.
 */
final class EnabledAuthMethods
{
    /**
     * Returns the method keys this installation offers, in the order a surface shows them.
     *
     * @return list<string> Enabled method keys (see AuthMethodKey)
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting's catalog entry or stored value is invalid
     */
    public static function keys(): array
    {
        $disabled = self::disabledKeys();

        return array_values(array_filter(
            Hilos::authMethodDirectoryClass()::keys(),
            static fn(string $key): bool => !in_array($key, $disabled, true),
        ));
    }

    /**
     * Whether a method is wired and switched on.
     *
     * @param string $methodKey Method key (see AuthMethodKey)
     * @return bool True when the installation offers the method
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting's catalog entry or stored value is invalid
     */
    public static function isEnabled(string $methodKey): bool
    {
        return in_array($methodKey, self::keys(), true);
    }

    /**
     * The enabled set in the shape a surface is handed it.
     *
     * A provider carries the name its button shows, taken from the project's provider
     * directory; every other method carries null, because the surface names it itself. Every
     * entry says whether the installation can serve it ({@see AuthMethodReadiness}): the set
     * is not narrowed to the ready methods here, since the switches of the sign-in methods
     * screen read it too, and the sign-in surfaces narrow it themselves.
     *
     * @return list<array{key: string, name: ?string, ready: bool}> Enabled methods in the order a surface shows them
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting's catalog entry or stored value is invalid
     */
    public static function toWire(): array
    {
        $entries = [];
        foreach (self::keys() as $methodKey) {
            $entries[] = [
                AuthMethodsSignalData::key => $methodKey,
                AuthMethodsSignalData::name => self::providerName($methodKey),
                AuthMethodsSignalData::ready => AuthMethodReadiness::isReady($methodKey),
            ];
        }

        return $entries;
    }

    /**
     * Reads the stored switched-off keys as they stand, or none when this installation has no such setting.
     *
     * As stored: a key the directory no longer names is still in it. The sign-in methods
     * screen rebuilds the list from this before writing it back.
     *
     * @return list<string> Switched-off method keys
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting's catalog entry or stored value is invalid
     */
    public static function disabledKeys(): array
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[AuthMethodSettings::DISABLED_KEY])) {
            return [];
        }

        return AuthMethodSettings::parse(Hilos::$setting[AuthMethodSettings::DISABLED_KEY]->string());
    }

    /**
     * The name a provider's button shows, or null for a method that is not a provider.
     *
     * @param string $methodKey Method key (see AuthMethodKey)
     * @return ?string Provider label from the provider directory, or null
     */
    private static function providerName(string $methodKey): ?string
    {
        if (!str_starts_with($methodKey, AuthMethodKey::OAUTH_PREFIX)) {
            return null;
        }

        return Hilos::oauthProviderDirectoryClass()::get($methodKey)?->label;
    }
}
