<?php

declare(strict_types=1);

namespace Demo\Polls\Auth;

use Demo\Polls\Hilos;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * PollsAuthMethodDirectory - the sign-in methods this demo has wired (HIL-634, HIL-427).
 *
 * What the demo CAN offer: every key here has a handler behind it. What it does offer is
 * this list narrowed by the administrator on the sign-in methods screen, so nothing is
 * switched off here. The providers come from {@see PollsOAuthProviderDirectory}, whose
 * order is the order of their icons, so adding a provider there adds it here with nothing
 * to keep in step.
 */
final class PollsAuthMethodDirectory extends AuthMethodDirectory
{
    /**
     * Password, passkey, the mailed link and the phone code, then every declared provider.
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::MAGIC_LINK,
            AuthMethodKey::SMS,
            ...array_keys(Hilos::oauthProviderDirectoryClass()::all()),
        ];
    }
}
