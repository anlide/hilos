<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Auth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * EcommerceShopAuthMethodDirectory - the sign-in methods this demo has wired.
 *
 * The password and nothing else: a registration and a recovery confirm the address by a code
 * the framework mails, which needs no method of its own here. Passkeys, the mailed link, the
 * phone code and the providers are the account side of the e2e, and that side lives in chat,
 * tasks and polls.
 */
final class EcommerceShopAuthMethodDirectory extends AuthMethodDirectory
{
    /**
     * The password.
     *
     * @return list<string> Method keys (see AuthMethodKey)
     */
    protected static function methods(): array
    {
        return [
            ...parent::methods(),
            AuthMethodKey::PASSWORD,
        ];
    }
}
