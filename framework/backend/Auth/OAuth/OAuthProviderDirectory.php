<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Constants\EnvConstants;
use Hilos\Hilos;

/**
 * OAuthProviderDirectory - the code-side catalog of a project's OAuth providers (HIL-286).
 *
 * Maps a provider key to its {@see OAuthProviderDescriptor}. The framework ships this
 * empty base (no providers); a project points {@see Hilos::OAUTH_PROVIDER_DIRECTORY} at
 * its own subclass and declares providers by overriding {@see providers()} with
 * `array_replace(parent::providers(), [...])`.
 *
 * The directory is the one place that declares the set, and its order is the order of
 * the sign-in icons: {@see OAuthConfigResolver::registry()} keeps it, the registry hands
 * its keys back as configured and the surface draws them in that order. The admin
 * screens are built from the same list, so a provider the directory does not name can
 * be neither configured nor signed in with.
 */
abstract class OAuthProviderDirectory
{
    /**
     * The provider descriptors keyed by provider key, in sign-in order.
     *
     * A project overrides this and merges its own entries onto the parent's:
     *
     * ```php
     * protected static function providers(): array
     * {
     *     return array_replace(parent::providers(), [
     *         OAuthProviderPreset::GITHUB->value => OAuthProviderDescriptor::fromPreset(OAuthProviderPreset::GITHUB, 'GitHub'),
     *     ]);
     * }
     * ```
     *
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    protected static function providers(): array
    {
        return [];
    }

    /**
     * The env variable carrying the shared return address when the administrator has not entered one.
     *
     * The return address is one for the whole application, not one per provider, so it is
     * declared here rather than on a descriptor. Null means the project reads it from
     * nowhere but the admin setting.
     *
     * @return EnvConstants|string|null Env variable name, or null for none
     */
    public static function redirectUriEnvKey(): EnvConstants|string|null
    {
        return null;
    }

    /**
     * Returns every declared provider descriptor keyed by its own key, in sign-in order.
     *
     * Keyed by the descriptor rather than by the map it came from, so a key typed twice in
     * a project's map cannot name a provider other than the one it holds.
     *
     * @return array<string, OAuthProviderDescriptor> Descriptors keyed by provider key
     */
    public static function all(): array
    {
        $providers = [];
        foreach (static::providers() as $descriptor) {
            $providers[$descriptor->key] = $descriptor;
        }

        return $providers;
    }

    /**
     * Returns one provider descriptor by key, or null when the project does not declare it.
     *
     * @param string $key Provider key, e.g. 'oauth:github'
     * @return ?OAuthProviderDescriptor Descriptor, or null when unknown
     */
    public static function get(string $key): ?OAuthProviderDescriptor
    {
        return static::all()[$key] ?? null;
    }
}
