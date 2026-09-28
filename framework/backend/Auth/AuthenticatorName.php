<?php

declare(strict_types=1);

namespace Hilos\Auth;

use Hilos\Auth\SecondFactor\OtpAuthUri;
use Hilos\Auth\WebAuthn\WebAuthnConfig;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Resolves the installation name shown by authenticator apps and passkey dialogs (HIL-1247).
 *
 * Outside production the environment is appended to the name (e.g. "Hilos (local)")
 * so an authenticator entry or passkey enrolled on a stand is distinguishable from a
 * production account in the user's authenticator list.
 *
 * Staging carries the environment as well: a staging stand is still a testing deployment,
 * which is why NonProductionGate is not reused here — that gate answers a different question
 * (safe automated destructive actions) and treats staging as production-like.
 *
 * An unrecognized or missing APP_ENV value is treated like production (no environment suffix):
 * an enrolled name remains on the user's phone permanently and cannot be updated by the server,
 * so the environment suffix is appended only when the node is known to be non-production.
 *
 * Used as the single source for the TOTP issuer and label in {@see OtpAuthUri} and the
 * WebAuthn Relying Party name in {@see WebAuthnConfig}.
 */
final class AuthenticatorName
{
    /**
     * Composes the authenticator display name for an installation and environment.
     *
     * @param string $installationName Base installation name (e.g. "Hilos")
     * @param ?AppEnv $env Resolved application environment, or null if unrecognized
     * @return string Display name, with environment appended outside production
     */
    public static function compose(string $installationName, ?AppEnv $env): string
    {
        if ($env === null || $env === AppEnv::PROD) {
            return $installationName;
        }

        return sprintf('%s (%s)', $installationName, $env->value);
    }

    /**
     * Resolves the authenticator display name from the environment configuration.
     *
     * @return string Display name resolved from HILOS_WEBAUTHN_RP_NAME and APP_ENV
     * @throws EnvException When APP_ENV or HILOS_WEBAUTHN_RP_NAME cannot be read
     */
    public static function fromEnv(): string
    {
        return self::compose(
            Hilos::$env[EnvConstants::HILOS_WEBAUTHN_RP_NAME]->string(),
            AppEnv::fromString(Hilos::$env[EnvConstants::APP_ENV]->string()),
        );
    }
}
