<?php

declare(strict_types=1);

namespace Hilos\Auth\Method;

use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Logger;

/**
 * PasskeyAddressPolicy - whether a passkey may be the only way into an account whose address is unproven (HIL-1105).
 *
 * One setting, yes or no, off by default: may a new account start with nothing but a passkey,
 * before its address is confirmed. The address is the email or the phone typed on the sign-in
 * surface - one setting answers for both - and on this road it is NOT stored (owner's decision,
 * 26.09.2026, HIL-1104): it labels the key in the device prompt and names the account, and the
 * account starts without a confirmed address, holding the passkey alone.
 *
 * IT DECIDES THE CREATION OF AN ACCOUNT, AND NOTHING ELSE (owner's decision, 24.09.2026). Turning
 * it off stops new accounts only: an account already created without a confirmed address goes
 * on signing in with its passkey as before, because signing in does not read this setting.
 * There is no way to confirm an address after signing in, so a sign-in gated on it would lock
 * those people out for good.
 *
 * Its readers on the server are the two submits of the door that registers an account by
 * passkey (HIL-1104), each at the moment it finds this browser's proven reservation on the typed
 * address or its absence: a proven reservation passes always, none only while this answers true.
 * The value also reaches every open tab with the sign-in method set ({@see AuthMethodsSignalData}),
 * because the surfaces read the two together.
 *
 * It fails CLOSED, where {@see AuthMethodReadiness} fails open: a wrong no here only asks the
 * person to confirm the address first - the path every installation had before this setting -
 * while a wrong yes would let an account start without a confirmed address. So a project whose
 * catalog lacks the key, and a read that fails, both answer no.
 *
 * Nothing is cached, for the reason {@see EnabledAuthMethods} gives: settings are read locally in
 * every process, and a switch takes effect on the next registration on every node.
 */
final class PasskeyAddressPolicy
{
    /** Setting key holding whether a passkey may start an account on an unproven address. */
    public const string SETTING_KEY = 'auth.passkey.allow_unproven_address';

    /**
     * Whether a new account may start with only a passkey, before its address is confirmed.
     *
     * @return bool True when the setting allows it; false by default, without the key, or when it cannot be read
     */
    public static function allowsUnproven(): bool
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[self::SETTING_KEY])) {
            return false;
        }

        try {
            return Hilos::$setting[self::SETTING_KEY]->bool();
        } catch (HilosException $e) {
            Logger::warning('Passkey unproven-address policy could not be read; counting it off', [
                'key' => self::SETTING_KEY,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
