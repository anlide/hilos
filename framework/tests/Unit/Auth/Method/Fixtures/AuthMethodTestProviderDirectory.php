<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Constants\EnvConstants;

/**
 * Two declared providers, GitHub before Google.
 *
 * GitHub reads its client pair from two env variables borrowed from the framework's own
 * catalog (a project's own keys are not in the stub catalog), so a case completes the pair
 * with putenv; Google names none and is never ready.
 */
final class AuthMethodTestProviderDirectory extends OAuthProviderDirectory
{
    /** Env variable standing in for GitHub's client id. */
    public const EnvConstants GITHUB_CLIENT_ID_ENV = EnvConstants::MAIL_SMTP_USERNAME;

    /** Env variable standing in for GitHub's client secret. */
    public const EnvConstants GITHUB_CLIENT_SECRET_ENV = EnvConstants::MAIL_SMTP_PASSWORD;

    /**
     * Completes GitHub's client pair in the env.
     */
    public static function configureGitHub(): void
    {
        putenv(self::GITHUB_CLIENT_ID_ENV->name . '=github-client');
        putenv(self::GITHUB_CLIENT_SECRET_ENV->name . '=github-secret');
    }

    /**
     * Takes GitHub's client pair out of the env again.
     */
    public static function forgetGitHub(): void
    {
        putenv(self::GITHUB_CLIENT_ID_ENV->name);
        putenv(self::GITHUB_CLIENT_SECRET_ENV->name);
    }

    /**
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GITHUB,
                'GitHub',
                self::GITHUB_CLIENT_ID_ENV,
                self::GITHUB_CLIENT_SECRET_ENV,
            ),
            OAuthProviderPreset::GOOGLE->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GOOGLE, 'Google'),
        ]);
    }
}
