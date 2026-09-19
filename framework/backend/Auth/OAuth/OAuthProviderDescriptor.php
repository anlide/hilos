<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Environment\Exception\EnvException;

/**
 * OAuthProviderDescriptor - what a project declares about one OAuth provider (HIL-286).
 *
 * The code half of a provider: its key, the name the admin screens show, its recipe,
 * and the env variables that carry its client pair when the administrator has not
 * entered one. What the administrator enters lives in hilos_oauth_provider, and
 * {@see OAuthConfigResolver} lays the two over each other.
 *
 * A provider comes either from a preset Hilos ships ({@see fromPreset()}) or from a
 * recipe the project builds by hand for a provider Hilos has never heard of
 * ({@see fromRecipe()}) - the path {@see OAuthProviderPreset} promises to keep open.
 * Exactly one of {@see $preset} and {@see $recipe} is set, and the two named
 * constructors are the only way in, so the key can never disagree with the recipe.
 */
final readonly class OAuthProviderDescriptor
{
    /**
     * @param string $key Stable provider key, e.g. 'oauth:github'
     * @param string $label Human provider name shown on the admin screens, e.g. 'GitHub'
     * @param ?OAuthProviderPreset $preset Framework recipe, or null for a hand-built one
     * @param ?OAuthProviderConfig $recipe Hand-built recipe, or null for a preset
     * @param EnvConstants|string|null $envClientIdKey Env variable carrying the client id, or null for none
     * @param EnvConstants|string|null $envClientSecretKey Env variable carrying the client secret, or null for none
     */
    private function __construct(
        public string $key,
        public string $label,
        public ?OAuthProviderPreset $preset,
        public ?OAuthProviderConfig $recipe,
        public EnvConstants|string|null $envClientIdKey,
        public EnvConstants|string|null $envClientSecretKey,
    ) {
    }

    /**
     * Declares a provider Hilos ships a recipe for.
     *
     * @param OAuthProviderPreset $preset Framework recipe; its value is the provider key
     * @param string $label Human provider name shown on the admin screens
     * @param EnvConstants|string|null $envClientIdKey Env variable carrying the client id, or null for none
     * @param EnvConstants|string|null $envClientSecretKey Env variable carrying the client secret, or null for none
     * @return self Descriptor under the preset's key
     */
    public static function fromPreset(
        OAuthProviderPreset $preset,
        string $label,
        EnvConstants|string|null $envClientIdKey = null,
        EnvConstants|string|null $envClientSecretKey = null,
    ): self {
        return new self($preset->value, $label, $preset, null, $envClientIdKey, $envClientSecretKey);
    }

    /**
     * Declares a provider by a recipe the project built itself.
     *
     * The recipe's client id, secret and scope are its lowest layer: what the
     * administrator enters, and then env, win over them.
     *
     * @param OAuthProviderConfig $recipe Hand-built recipe; its key is the provider key
     * @param string $label Human provider name shown on the admin screens
     * @param EnvConstants|string|null $envClientIdKey Env variable carrying the client id, or null for none
     * @param EnvConstants|string|null $envClientSecretKey Env variable carrying the client secret, or null for none
     * @return self Descriptor under the recipe's key
     */
    public static function fromRecipe(
        OAuthProviderConfig $recipe,
        string $label,
        EnvConstants|string|null $envClientIdKey = null,
        EnvConstants|string|null $envClientSecretKey = null,
    ): self {
        return new self($recipe->key, $label, null, $recipe, $envClientIdKey, $envClientSecretKey);
    }

    /**
     * The recipe this provider runs on, before the project's own data is laid over it.
     *
     * A preset's recipe carries empty client data; a hand-built one carries whatever the
     * project put in it.
     *
     * @return OAuthProviderConfig Recipe of this provider
     * @throws EnvException When a preset's OAUTH_ENDPOINT_URL is missing, outside the catalog, or of the wrong type
     * @throws LogicException When the descriptor carries neither a preset nor a recipe, which its constructors never build
     */
    public function recipeConfig(): OAuthProviderConfig
    {
        if ($this->preset !== null) {
            return $this->preset->config('', '', '');
        }

        return $this->recipe ?? throw new LogicException("OAuth provider '{$this->key}' declares neither a preset nor a recipe");
    }
}
