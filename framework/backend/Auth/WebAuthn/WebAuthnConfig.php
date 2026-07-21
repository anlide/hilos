<?php

declare(strict_types=1);

namespace Hilos\Auth\WebAuthn;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * The fully-resolved WebAuthn configuration for the passkey ceremonies (HIL-284).
 *
 * The immutable recipe both ceremonies run on: the Relying Party identity
 * (`rpId` / `rpName`), the set of origins a `clientDataJSON.origin` may match,
 * the challenge lifetime and HMAC secret backing the stateless challenge token,
 * and the user-verification / timeout hints echoed into the client publicKey
 * options. Values come from env (`HILOS_WEBAUTHN_*`) via {@see fromEnv()};
 * tests build one directly. `challengeSecret` is env-only and never synced to a
 * client.
 */
final readonly class WebAuthnConfig
{
    public const string USER_VERIFICATION_REQUIRED = 'required';
    public const string USER_VERIFICATION_PREFERRED = 'preferred';
    public const string USER_VERIFICATION_DISCOURAGED = 'discouraged';

    /**
     * @param string $rpId Relying Party id (registrable domain, no scheme/port)
     * @param string $rpName Human-readable Relying Party name for the authenticator UI
     * @param list<string> $origins Allowed ceremony origins (scheme://host[:port]); clientDataJSON.origin must match one exactly
     * @param int $challengeTtlSeconds Seconds a challenge token stays valid
     * @param string $userVerification Requested UV level: required | preferred | discouraged
     * @param int $timeoutMs Client ceremony timeout in milliseconds
     * @param string $challengeSecret HMAC secret for the stateless challenge token (env-only)
     */
    public function __construct(
        public string $rpId,
        public string $rpName,
        public array $origins,
        public int $challengeTtlSeconds,
        public string $userVerification,
        public int $timeoutMs,
        public string $challengeSecret,
    ) {
    }

    /**
     * Builds the configuration from the `HILOS_WEBAUTHN_*` environment values.
     *
     * The origin list is the comma-separated `HILOS_WEBAUTHN_ORIGIN` split and
     * trimmed, dropping empties so a trailing comma cannot admit a blank origin.
     *
     * @return self Resolved configuration
     * @throws EnvException When a WebAuthn env value is missing from the catalog or has the wrong type
     */
    public static function fromEnv(): self
    {
        return new self(
            Hilos::$env->string(EnvConstants::HILOS_WEBAUTHN_RP_ID),
            Hilos::$env->string(EnvConstants::HILOS_WEBAUTHN_RP_NAME),
            self::parseOrigins(Hilos::$env->string(EnvConstants::HILOS_WEBAUTHN_ORIGIN)),
            Hilos::$env->int(EnvConstants::HILOS_WEBAUTHN_CHALLENGE_TTL_SEC),
            Hilos::$env->string(EnvConstants::HILOS_WEBAUTHN_USER_VERIFICATION),
            Hilos::$env->int(EnvConstants::HILOS_WEBAUTHN_TIMEOUT_MS),
            Hilos::$env->string(EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET),
        );
    }

    /**
     * Whether a ceremony origin is one of the configured allowed origins.
     *
     * @param string $origin The `origin` field read from clientDataJSON
     * @return bool True when the origin matches a configured entry exactly
     */
    public function isAllowedOrigin(string $origin): bool
    {
        return in_array($origin, $this->origins, true);
    }

    /**
     * The raw SHA-256 of the RP id, as embedded in authenticatorData.
     *
     * @return string 32 raw bytes
     */
    public function rpIdHash(): string
    {
        return hash('sha256', $this->rpId, true);
    }

    /**
     * Whether the configured policy demands the user-verified (UV) flag be set.
     *
     * @return bool True only for the `required` level
     */
    public function requiresUserVerification(): bool
    {
        return $this->userVerification === self::USER_VERIFICATION_REQUIRED;
    }

    /**
     * @param string $csv Comma-separated origin list
     * @return list<string> Trimmed, non-empty origins
     */
    private static function parseOrigins(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $o): bool => $o !== ''));
    }
}
