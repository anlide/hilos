<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Auth;

use Demo\ConcertTickets\Constants\ConcertTicketsEnvConstants;
use Hilos\Auth\OAuth\OAuthProviderDescriptor;
use Hilos\Auth\OAuth\OAuthProviderDirectory;
use Hilos\Auth\OAuth\OAuthProviderPreset;

/**
 * ConcertTicketsOAuthProviderDirectory - the OAuth providers the concert-tickets demo offers sign-in with (HIL-286).
 *
 * The one place that declares the set, and its order is the order of the icon row. Each
 * provider rides a recipe Hilos ships ({@see OAuthProviderPreset}) and names the env
 * variables that carry its client pair when an administrator has entered none in the
 * admin. A provider Hilos ships no preset for would be declared here too, from a recipe
 * built by hand ({@see OAuthProviderDescriptor::fromRecipe()}).
 */
final class ConcertTicketsOAuthProviderDirectory extends OAuthProviderDirectory
{
    /**
     * GitHub.
     *
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return array_replace(parent::providers(), [
            OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(
                OAuthProviderPreset::GITHUB,
                'GitHub',
                ConcertTicketsEnvConstants::OAUTH_GITHUB_CLIENT_ID,
                ConcertTicketsEnvConstants::OAUTH_GITHUB_CLIENT_SECRET,
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
        return ConcertTicketsEnvConstants::OAUTH_REDIRECT_URI;
    }
}
