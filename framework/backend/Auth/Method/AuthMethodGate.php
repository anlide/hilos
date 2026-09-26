<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Pages\AbstractHilosProfilePage;

/**
 * AuthMethodGate - the server-side lock on a switched-off sign-in method (HIL-427).
 *
 * Hiding a method's button is the surface's half; this is the half that makes switching a
 * method off true. Every sign-in action belongs to a method, and an action whose method is
 * off is refused with an ordinary action error - including one that continues a flow begun
 * before the switch, so a code screen left open does not outlive the method it was for.
 *
 * The map names each action with the methods ANY of which opens it. The mailed code that
 * starts a registration serves both registration roads, the one ending in a password and the
 * one ending without, so it stays open while either of them is. Two actions are in no method:
 * the identifier lookup, which answers what is open rather than using a way in, and
 * canceling a registration, which only ever closes something.
 *
 * A provider login is not in the map, because its action names no method: which provider it
 * is rides in the payload, or in a signed token. {@see OAuthCommands} asks
 * {@see assertProviderOpen()} where the provider is known, and so does the start of linking a
 * provider from the profile ({@see AbstractHilosProfilePage}, HIL-1137).
 *
 * The lock closes what an administrator switched off, and only that. An action none of whose
 * methods the project wired is left to answer as it did before the set existed - a project
 * that never declared a directory ({@see AuthMethodDirectory}) has nothing to switch, and a
 * provider it never declared is refused by its command as unknown, not as turned off.
 */
final class AuthMethodGate
{
    /** Each gated action and the methods any of which keeps it open. */
    private const array ACTION_METHODS = [
        HilosSignalConstants::HILOS_LOGIN => [AuthMethodKey::PASSWORD],
        HilosSignalConstants::HILOS_REGISTER => [AuthMethodKey::PASSWORD],
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => [AuthMethodKey::PASSWORD],
        HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => [AuthMethodKey::PASSWORD],
        HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => [AuthMethodKey::PASSWORD],
        HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => [AuthMethodKey::PASSWORD],
        HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => [AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK],
        HilosSignalConstants::HILOS_CONFIRM_REGISTER => [AuthMethodKey::PASSWORD, AuthMethodKey::MAGIC_LINK],
        HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => [AuthMethodKey::MAGIC_LINK],
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => [AuthMethodKey::MAGIC_LINK],
        HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => [AuthMethodKey::MAGIC_LINK],
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS => [AuthMethodKey::MAGIC_LINK],
        HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => [AuthMethodKey::SMS],
        HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => [AuthMethodKey::SMS],
        HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => [AuthMethodKey::PASSKEY],
        HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => [AuthMethodKey::PASSKEY],
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => [AuthMethodKey::PASSKEY],
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => [AuthMethodKey::PASSKEY],
    ];

    /**
     * Refuses a sign-in action whose every wired method is switched off.
     *
     * An action the map does not name passes: it is either in no method or a provider
     * login, which its command checks by provider. So does an action none of whose methods
     * the project wired.
     *
     * @param string $action Action name a sign-in surface submitted (see {@see AbstractUsersLibraryAgent})
     * @throws ValidationException When every method that opens the action is switched off
     * @throws DatabaseException When the stored method setting cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     */
    public static function assertActionOpen(string $action): void
    {
        $methods = self::ACTION_METHODS[$action] ?? null;
        if ($methods === null) {
            return;
        }

        $wired = array_intersect($methods, Hilos::authMethodDirectoryClass()::keys());
        if ($wired === [] || array_intersect($wired, EnabledAuthMethods::keys()) !== []) {
            return;
        }

        throw new ValidationException(AuthMessages::METHOD_TURNED_OFF);
    }

    /**
     * Refuses a provider login, or a provider link, whose provider is switched off.
     *
     * A provider the project's method directory does not name passes, so that its command
     * refuses it as unknown.
     *
     * @param string $providerKey Provider key, which is its method key, e.g. 'oauth:github'
     * @throws ValidationException When the provider's method is wired and switched off
     * @throws DatabaseException When the stored method setting cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     */
    public static function assertProviderOpen(string $providerKey): void
    {
        if (Hilos::authMethodDirectoryClass()::has($providerKey) && !EnabledAuthMethods::isEnabled($providerKey)) {
            throw new ValidationException(AuthMessages::METHOD_TURNED_OFF);
        }
    }
}
