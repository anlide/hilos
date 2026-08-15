<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * The account identity resolved from an OAuth provider's userinfo (HIL-281).
 *
 * The stable output of the exchange: `subject` is the provider's immutable
 * account id (the second half of the `provider:subject` identity identifier);
 * `email` and `name` are the provider-reported address and display name, each
 * null when the provider withholds it (HIL-573 — absence is the type, not an
 * empty-string label). Per the settled surface, account resolution keys strictly
 * on (provider, subject) — `email` is carried for display/registration only and
 * is never consulted for account resolution here (the email-collision/merge
 * policy is deferred to HIL-282); `name` is offered to the project, which alone
 * decides how it names a person.
 */
final readonly class OAuthUserInfo
{
    /**
     * @param string $subject Provider-immutable account id (non-empty)
     * @param ?string $email Provider-reported email (trimmed, lowercased), or null when absent
     * @param ?string $name Provider-reported display name (trimmed), or null when absent
     */
    public function __construct(
        public string $subject,
        public ?string $email,
        public ?string $name,
    ) {
    }
}
