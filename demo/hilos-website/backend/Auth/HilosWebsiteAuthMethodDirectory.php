<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Auth;

use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Method\AuthMethodDirectory;

/**
 * HilosWebsiteAuthMethodDirectory - the sign-in methods of the site and its closed registration.
 *
 * The site has one account: the owner's, handed out by the admin:create command. Nobody opens
 * an account here by themselves, so the directory closes registration and wires the password
 * alone - a method whose sign-in opens an account by itself would be refused at start beside a
 * closed registration. A password reset and a password added on the profile confirm the address
 * by a code the framework mails, which needs no method of its own here.
 */
final class HilosWebsiteAuthMethodDirectory extends AuthMethodDirectory
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

    /**
     * Registration is closed: the only account is the one admin:create hands out.
     *
     * @return bool Always false
     */
    protected static function registers(): bool
    {
        return false;
    }
}
