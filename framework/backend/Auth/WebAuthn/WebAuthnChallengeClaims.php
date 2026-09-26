<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

/**
 * The verified claims recovered from a signed WebAuthn challenge token (HIL-284).
 *
 * What {@see WebAuthnChallengeSigner::verify()} returns once a token's signature,
 * purpose, session binding and expiry have all checked out: the original
 * {@see $challenge} the caller must compare against clientDataJSON, the
 * {@see $purpose} the token was minted for, and the {@see $userId} the register
 * ceremony bound into the token (null for login, which is not tied to a resolved
 * user yet).
 *
 * The purpose is only news to a caller that accepted more than one
 * ({@see WebAuthnChallengeSigner::verifyOneOf()}, HIL-1104): the passkey door of a new
 * account reads back off it which road its first submit chose.
 */
final readonly class WebAuthnChallengeClaims
{
    /**
     * @param string $challenge base64url challenge the token was minted for
     * @param string $purpose Ceremony purpose the token was minted for (a WebAuthnChallengeSigner::PURPOSE_* value)
     * @param ?int $userId User the register challenge was bound to, or null for login
     */
    public function __construct(
        public string $challenge,
        public string $purpose,
        public ?int $userId,
    ) {
    }
}
