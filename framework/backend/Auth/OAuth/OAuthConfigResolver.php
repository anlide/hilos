<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Database\View\Item\OAuthProvider;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * OAuthConfigResolver - resolves what an OAuth provider actually runs on (HIL-286).
 *
 * The one place the precedence of an OAuth provider's data lives: what the administrator
 * entered (the provider's row in hilos_oauth_provider) on top of the env value on top of
 * the recipe's own value. The admin screens read it to show which layer won, and every
 * caller that builds a provider registry reads it to build one, so the screen and the
 * sign-in never disagree about the credentials in force.
 *
 * The client secret is resolved to its source and its set/not-set state only
 * ({@see resolve()}); the one method that hands its value out builds the configuration
 * the exchange runs on ({@see providerConfig()}), which never travels to a browser.
 *
 * Nothing here is cached. A registry is built from the rows as they are at the moment of
 * the build, so a changed credential is in force on the next sign-in, on every node.
 */
final class OAuthConfigResolver
{
    /** Field name the shared return address resolves under. */
    public const string REDIRECT_URI_FIELD = 'redirect_uri';

    /**
     * @param ?class-string<OAuthProviderDirectory> $directoryClass Directory to read, or null for the project's own
     */
    public function __construct(private readonly ?string $directoryClass = null)
    {
    }

    /**
     * Resolves the effective value and source of one field of one provider.
     *
     * @param OAuthProviderDescriptor $descriptor Provider the field belongs to
     * @param OAuthConfigField $field Field to resolve
     * @return ResolvedOAuthConfig Effective value and its source (value null for the client secret)
     * @throws DatabaseException When the provider's row cannot be read
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or the descriptor has no recipe
     * @throws InvalidArgumentException When the provider row lookup is given an invalid query
     */
    public function resolve(OAuthProviderDescriptor $descriptor, OAuthConfigField $field): ResolvedOAuthConfig
    {
        $row = $this->row($descriptor->key);
        $recipe = $descriptor->recipeConfig();

        return match ($field) {
            OAuthConfigField::CLIENT_ID => $this->layered(
                $field->value,
                $row?->clientId,
                $descriptor->envClientIdKey,
                $recipe->clientId,
            ),
            OAuthConfigField::SCOPE => $this->layered($field->value, $row?->scope, null, $recipe->scope),
            OAuthConfigField::CLIENT_SECRET => $this->secretState($descriptor, $row, $recipe),
        };
    }

    /**
     * Resolves the shared return address every provider redirects back to.
     *
     * The admin setting ({@see OAuthSettingsCatalog::REDIRECT_URI_KEY}) when a non-empty one
     * is persisted, then the env variable the directory names, then empty.
     *
     * @return ResolvedOAuthConfig Effective return address and its source
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When the env variable value is invalid for its type
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    public function resolveRedirectUri(): ResolvedOAuthConfig
    {
        [$source, $value] = $this->pickRedirectUri();

        return new ResolvedOAuthConfig(self::REDIRECT_URI_FIELD, $source, $value, $value !== '');
    }

    /**
     * Builds the configuration one provider signs in on, or null when it cannot sign anyone in.
     *
     * A provider whose client id or secret resolves empty is not an error but a provider
     * this installation does not offer, the same on every node (HIL-924): the caller leaves
     * it out, and it is gone from the icon row, the identifier detection and the agent at once.
     *
     * @param OAuthProviderDescriptor $descriptor Provider to build
     * @return ?OAuthProviderConfig Effective configuration, or null when the client pair is incomplete
     * @throws DatabaseException When the provider's row or the return-address setting cannot be read
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or the descriptor has no recipe
     * @throws InvalidArgumentException When a row lookup is given an invalid query
     */
    public function providerConfig(OAuthProviderDescriptor $descriptor): ?OAuthProviderConfig
    {
        $row = $this->row($descriptor->key);
        $recipe = $descriptor->recipeConfig();

        [, $clientId] = $this->pick($row?->clientId, $descriptor->envClientIdKey, $recipe->clientId);
        $clientSecret = $row?->readClientSecret() ?? $this->envValue($descriptor->envClientSecretKey) ?? $recipe->clientSecret;
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }
        [, $scope] = $this->pick($row?->scope, null, $recipe->scope);
        [, $redirectUri] = $this->pickRedirectUri();

        return new OAuthProviderConfig(
            key: $recipe->key,
            clientId: $clientId,
            clientSecret: $clientSecret,
            authorizeUrl: $recipe->authorizeUrl,
            tokenUrl: $recipe->tokenUrl,
            userInfoUrl: $recipe->userInfoUrl,
            scope: $scope,
            redirectUri: $redirectUri,
            subjectKey: $recipe->subjectKey,
            emailKey: $recipe->emailKey,
            nameKey: $recipe->nameKey,
        );
    }

    /**
     * Builds the registry of every declared provider that can sign someone in, in directory order.
     *
     * @return OAuthProviderRegistry Providers keyed by provider key, in sign-in order
     * @throws DatabaseException When a provider's row or the return-address setting cannot be read
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When an env value is invalid for its type, or a preset's endpoint base cannot be read
     * @throws LogicException When the collection classes are misconfigured or a descriptor has no recipe
     * @throws InvalidArgumentException When a row lookup is given an invalid query
     */
    public function registry(): OAuthProviderRegistry
    {
        $providers = [];
        foreach ($this->directoryClass()::all() as $descriptor) {
            $config = $this->providerConfig($descriptor);
            if ($config !== null) {
                $providers[] = new GenericOAuthProvider($config);
            }
        }

        return new OAuthProviderRegistry($providers);
    }

    /**
     * Resolves one non-secret field through the three layers.
     *
     * @param string $field Field name the resolution is reported under
     * @param ?string $stored What the administrator entered, or null for nothing
     * @param EnvConstants|string|null $envKey Env variable backing the field, or null for none
     * @param string $default The recipe's own value
     * @return ResolvedOAuthConfig Effective value and the layer it came from
     * @throws EnvException When the env variable value is invalid for its type
     */
    private function layered(string $field, ?string $stored, EnvConstants|string|null $envKey, string $default): ResolvedOAuthConfig
    {
        [$source, $value] = $this->pick($stored, $envKey, $default);

        return new ResolvedOAuthConfig($field, $source, $value, $value !== '');
    }

    /**
     * Picks the layer a non-secret value comes from, and the value.
     *
     * A stored or env value counts only when it is non-empty: an empty one is a layer
     * that says nothing, and the next one down answers.
     *
     * @param ?string $stored What the administrator entered, or null for nothing
     * @param EnvConstants|string|null $envKey Env variable backing the value, or null for none
     * @param string $default The recipe's own value
     * @return array{0: OAuthConfigSource, 1: string} Winning layer and its value
     * @throws EnvException When the env variable value is invalid for its type
     */
    private function pick(?string $stored, EnvConstants|string|null $envKey, string $default): array
    {
        if ($stored !== null && $stored !== '') {
            return [OAuthConfigSource::DB, $stored];
        }

        $envValue = $this->envValue($envKey);
        if ($envValue !== null) {
            return [OAuthConfigSource::ENV, $envValue];
        }

        return [OAuthConfigSource::DEFAULT, $default];
    }

    /**
     * Picks the layer the shared return address comes from, and the address.
     *
     * @return array{0: OAuthConfigSource, 1: string} Winning layer and its value
     * @throws DatabaseException When the persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When the env variable value is invalid for its type
     * @throws LogicException When the settings collection classes are misconfigured
     * @throws InvalidArgumentException When the settings lookup is given an invalid query
     */
    private function pickRedirectUri(): array
    {
        $settingKey = OAuthSettingsCatalog::REDIRECT_URI_KEY;
        $stored = null;
        if (Hilos::$setting !== null && Hilos::$db instanceof HilosDbContext && Hilos::$db->settings[$settingKey]?->value !== null) {
            $stored = Hilos::$setting[$settingKey]->string();
        }

        // An empty stored address says nothing, as an empty stored client id does: the general
        // settings screen can save one, and it must not shadow the address env carries.
        return $this->pick($stored, $this->directoryClass()::redirectUriEnvKey(), '');
    }

    /**
     * Reports where the client secret comes from and whether there is one, never its value.
     *
     * @param OAuthProviderDescriptor $descriptor Provider the secret belongs to
     * @param ?OAuthProvider $row The provider's row, or null when it has none
     * @param OAuthProviderConfig $recipe The provider's recipe
     * @return ResolvedOAuthConfig Source and set/not-set state, value always null
     * @throws DatabaseException When the secret lookup query fails
     * @throws EnvException When the env variable value is invalid for its type
     */
    private function secretState(
        OAuthProviderDescriptor $descriptor,
        ?OAuthProvider $row,
        OAuthProviderConfig $recipe,
    ): ResolvedOAuthConfig {
        $field = OAuthConfigField::CLIENT_SECRET->value;
        if ($row?->hasClientSecret() === true) {
            return new ResolvedOAuthConfig($field, OAuthConfigSource::DB, null, true);
        }
        if ($this->envValue($descriptor->envClientSecretKey) !== null) {
            return new ResolvedOAuthConfig($field, OAuthConfigSource::ENV, null, true);
        }

        return new ResolvedOAuthConfig($field, OAuthConfigSource::DEFAULT, null, $recipe->clientSecret !== '');
    }

    /**
     * Reads the provider's row, or null when it has none or no framework database is mounted.
     *
     * @param string $providerKey Provider key
     * @return ?OAuthProvider Provider row, or null
     * @throws DatabaseException When the row lookup fails
     * @throws LogicException When the collection classes are misconfigured
     * @throws InvalidArgumentException When the row lookup is given an invalid query
     */
    private function row(string $providerKey): ?OAuthProvider
    {
        if (!Hilos::$db instanceof HilosDbContext) {
            return null;
        }

        return Hilos::$db->oauthProviders[$providerKey];
    }

    /**
     * Reads a non-empty env value, or null when the key is absent, unset or empty.
     *
     * @param EnvConstants|string|null $envKey Env variable name, or null for none
     * @return ?string Non-empty env value, or null
     * @throws EnvException When the env variable value is invalid for its type
     */
    private function envValue(EnvConstants|string|null $envKey): ?string
    {
        if ($envKey === null || Hilos::$env === null) {
            return null;
        }

        $value = Hilos::$env[$envKey]->string();

        return $value !== '' ? $value : null;
    }

    /**
     * The directory this resolver reads: the one it was given, or the project's own.
     *
     * @return class-string<OAuthProviderDirectory> Directory class
     */
    private function directoryClass(): string
    {
        return $this->directoryClass ?? Hilos::oauthProviderDirectoryClass();
    }
}
