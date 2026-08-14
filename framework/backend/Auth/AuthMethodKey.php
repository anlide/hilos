<?php

declare(strict_types=1);

namespace Hilos\Auth;

use Hilos\Database\Identity\IdentityType;

/**
 * AuthMethodKey - the literal auth-method keys the identifier-first surface names (HIL-414).
 *
 * The backend mirror of the method keys in `@hilos/core` (`auth/authFlow.ts`):
 * detection answers with them, the surface reveals its affordances from them, and
 * a project declares which of them it enables. They are wire values, not internal
 * names, so they are fixed here once instead of being spelled out per leaf.
 *
 * A method key is NOT an {@see IdentityType}: the types
 * are the closed set of rows the identity table stores, while these are what a
 * person can sign in WITH. They coincide for password/magic_link/sms and part
 * ways on OAuth - one `oauth` identity type covers every provider, but each
 * provider is its own method key, `oauth:` followed by the provider key, because
 * the surface has to name the provider on a button.
 *
 * `passkey` is deliberately absent: this surface offers a device key only on an
 * empty field (discoverable-only, HIL-418), so detection never returns it and
 * nothing enables it here.
 */
final class AuthMethodKey
{
    /** Sign in with a password on the shared identifier field. */
    public const string PASSWORD = 'password';

    /** Sign in (or register) with a one-time link mailed to the address. */
    public const string MAGIC_LINK = 'magic_link';

    /** Sign in (or register) with a code sent to the phone number. */
    public const string SMS = 'sms';

    /**
     * Prefix every OAuth method key carries, followed by the provider name.
     *
     * A provider key is already stored in this full form (`oauth:github`), so this
     * is what recognizes one, not something prepended to it a second time.
     */
    public const string OAUTH_PREFIX = 'oauth:';
}
