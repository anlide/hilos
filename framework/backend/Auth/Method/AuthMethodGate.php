<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\Command\AbstractLibraryCommands;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\Command\OAuthCommands;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\Pages\AbstractHilosProfileSignInPage;

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
 * provider from the profile ({@see AbstractHilosProfileSignInPage}, HIL-1137).
 *
 * The lock closes what an administrator switched off, and only that. An action none of whose
 * methods the project wired is left to answer as it did before the set existed - a project
 * that never declared a directory ({@see AuthMethodDirectory}) has nothing to switch, and a
 * provider it never declared is refused by its command as unknown, not as turned off.
 *
 * One more lock stands in front of it, and it is the project's, not an administrator's: a
 * project that registers nobody ({@see AuthMethodDirectory::opensRegistration()}, HIL-2185)
 * has every action that only opens an account refused, before anything is written and whether
 * or not it wired the action's method - leaving an unwired registration to answer as it did
 * would open an account in a project that declared it opens none. Signing in, a password reset
 * and canceling a registration stay open: the first two serve an account that exists, the last
 * only closes what was begun.
 *
 * The magic link and the phone code are not on that list, because one letter of theirs both
 * signs a member in and registers a free address or number. Their registration is refused
 * further in, where the free identifier would be held
 * ({@see RegistrationReservationService::hold()}), and again where an account would be minted
 * ({@see AbstractLibraryCommands::landRegistration()}).
 */
final class AuthMethodGate
{
    /** The actions that open an account, all refused while the project registers nobody. */
    private const array REGISTRATION_ACTIONS = [
        HilosSignalConstants::HILOS_REGISTER,
        HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM,
        HilosSignalConstants::HILOS_CONFIRM_REGISTER,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS,
        HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS,
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY,
    ];

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
        HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS => [AuthMethodKey::PASSKEY],
        HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY => [AuthMethodKey::PASSKEY],
    ];

    /**
     * Refuses a registration in a project that registers nobody, and a sign-in action whose every wired method is switched off.
     *
     * A registration is asked first, and by the project's word alone: closed registration
     * refuses it even when the project never wired its method. Otherwise an action the map
     * does not name passes: it is either in no method or a provider login, which its command
     * checks by provider. So does an action none of whose methods the project wired.
     *
     * @param string $action Action name a sign-in surface submitted (see {@see AbstractUsersLibraryAgent})
     * @throws ValidationException When the action opens an account and the project registers nobody,
     *     or every method that opens the action is switched off
     * @throws DatabaseException When the stored method setting cannot be read
     * @throws SettingException When the method setting's catalog entry or stored value is invalid
     */
    public static function assertActionOpen(string $action): void
    {
        if (in_array($action, self::REGISTRATION_ACTIONS, true) && !Hilos::authMethodDirectoryClass()::opensRegistration()) {
            throw new ValidationException(AuthMessages::REGISTRATION_CLOSED);
        }

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
