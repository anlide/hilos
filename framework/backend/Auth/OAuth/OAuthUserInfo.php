<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * The account identity resolved from an OAuth provider's userinfo (HIL-281).
 *
 * The stable output of the exchange: `subject` is the provider's immutable
 * account id (the second half of the `provider:subject` identity identifier);
 * `email` is the provider-reported address, empty when the provider withholds
 * it. Per the settled surface, account resolution keys strictly on
 * (provider, subject) — `email` is carried for display/registration only and is
 * never consulted for account resolution here (the email-collision/merge policy
 * is deferred to HIL-282).
 */
final readonly class OAuthUserInfo
{
    /**
     * @param string $subject Provider-immutable account id (non-empty)
     * @param string $email Provider-reported email (lowercased), or '' when absent
     */
    public function __construct(
        public string $subject,
        public string $email,
    ) {
    }
}
