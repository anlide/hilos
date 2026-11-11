<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Auth;

use Demo\ConcertTickets\Hilos;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * ConcertTicketsAuthMethodDirectory - the sign-in methods this demo has wired.
 *
 * What the demo CAN offer: every key here has a handler behind it. What it does offer is
 * this list narrowed by the administrator on the sign-in methods screen, so nothing is
 * switched off here. The providers come from {@see ConcertTicketsOAuthProviderDirectory},
 * whose order is the order of their icons, so adding a provider there adds it here with
 * nothing to keep in step. A registration and a recovery confirm the address by a code the
 * framework mails, which needs no method of its own here.
 */
final class ConcertTicketsAuthMethodDirectory extends AuthMethodDirectory
{
    /**
     * Password, passkey and the phone code, then every declared provider.
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::SMS,
            ...array_keys(Hilos::oauthProviderDirectoryClass()::all()),
        ];
    }
}
