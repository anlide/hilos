<?php

declare(strict_types=1);

namespace Demo\Polls\Auth;

use Demo\Polls\Constants\PollsEnvConstants;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;

/**
 * PollsOAuthProviderDirectory - the OAuth providers the polls demo offers sign-in with (HIL-286).
 *
 * The one place that declares the set, and its order is the order of the icon row. Each
 * provider rides a recipe Hilos ships ({@see OAuthProviderPreset}) and names the env
 * variables that carry its client pair when an administrator has entered none in the
 * admin. A provider Hilos ships no preset for would be declared here too, from a recipe
 * built by hand ({@see OAuthProviderDescriptor::fromRecipe()}).
 */
final class PollsOAuthProviderDirectory extends OAuthProviderDirectory
{
    /**
     * GitHub, then Google.
     *
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GITHUB,
                'GitHub',
                PollsEnvConstants::OAUTH_GITHUB_CLIENT_ID,
                PollsEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
            ),
            OAuthProviderPreset::GOOGLE->value => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GOOGLE,
                'Google',
                PollsEnvConstants::OAUTH_GOOGLE_CLIENT_ID,
                PollsEnvConstants::OAUTH_GOOGLE_CLIENT_SECRET,
            ),
        ]);
    }

    /**
     * The env variable carrying the SPA callback when an administrator has entered none.
     *
     * @return string Env variable name
     */
    public static function redirectUriEnvKey(): string
    {
        return PollsEnvConstants::OAUTH_REDIRECT_URI;
    }
}
