<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Hilos;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\Exception\LLMConfigurationException;

/**
 * Resolves named LLM profiles and builds chat clients for them.
 *
 * The single policy layer above {@see ClientFactory}: it turns a profile key
 * into an immutable {@see LlmProfile} (provider explicit, resolved from the
 * env-backed catalog plus an optional runtime override), then builds the async
 * chat client for it. Replaces the global LLM_CHAT_PROVIDER switch read at
 * multiple call sites; that switch is now the `default` profile's provider env.
 */
class LlmRouter
{
    /** @var class-string<CatalogProviderInterface> Profile catalog provider class */
    private string $catalogClass;

    private ?LlmProfileOverrideSource $overrides;

    /**
     * @param class-string<CatalogProviderInterface> $catalogClass Profile catalog provider class
     * @param ?LlmProfileOverrideSource $overrides Runtime override source, or null for env-only
     */
    public function __construct(
        string $catalogClass = LlmProfileCatalogStub::class,
        ?LlmProfileOverrideSource $overrides = null,
    ) {
        $this->catalogClass = $catalogClass;
        $this->overrides = $overrides;
    }

    /**
     * Resolves a profile key to an immutable, fully-configured profile.
     *
     * @param string $profileKey Profile key, e.g. 'default'
     * @return LlmProfile Resolved profile
     * @throws LLMConfigurationException When the key is not declared, the provider
     *   is invalid, or the external provider is selected without an API key
     */
    public function resolve(string $profileKey): LlmProfile
    {
        $catalog = ($this->catalogClass)::getCatalog();
        if (!array_key_exists($profileKey, $catalog)) {
            throw new LLMConfigurationException("LLM profile '{$profileKey}' is not declared in the catalog");
        }
        $entry = $catalog[$profileKey];

        $provider = LlmProvider::fromValue(Hilos::$env[$entry[LlmProfileCatalogConstants::PROVIDER_ENV]]);

        if ($provider === LlmProvider::EXTERNAL) {
            $url = Hilos::$env[$entry[LlmProfileCatalogConstants::EXTERNAL_URL_ENV]];
            $model = Hilos::$env[$entry[LlmProfileCatalogConstants::EXTERNAL_MODEL_ENV]];
            $apiKey = Hilos::$env[$entry[LlmProfileCatalogConstants::API_KEY_ENV]];
            if ($apiKey === '') {
                throw new LLMConfigurationException(
                    "LLM profile '{$profileKey}' selects the external provider but has no API key",
                );
            }
        } else {
            $url = Hilos::$env->normalizedLlmUrl(
                $entry[LlmProfileCatalogConstants::LOCAL_URL_ENV],
                $entry[LlmProfileCatalogConstants::LOCAL_URL_FALLBACK_ENV] ?? null,
            );
            $model = Hilos::$env[$entry[LlmProfileCatalogConstants::LOCAL_MODEL_ENV]];
            $apiKey = null;
        }

        $timeoutEnv = $entry[LlmProfileCatalogConstants::TIMEOUT_ENV] ?? null;
        $timeoutSec = $timeoutEnv !== null
            ? Hilos::$env->float($timeoutEnv)
            : LLMConstants::DEFAULT_TIMEOUT_SEC;

        $profile = new LlmProfile(
            $profileKey,
            $provider,
            $url,
            $model,
            $apiKey,
            $timeoutSec,
            $entry[LlmProfileCatalogConstants::PLACEMENT] ?? null,
        );

        return $this->overrides?->override($profile) ?? $profile;
    }

    /**
     * Builds (or reuses) an async chat client for a resolved profile.
     *
     * @param string $profileKey Profile key, e.g. 'default'
     * @return AsyncChatLLMInterface Async chat client configured for the profile
     * @throws LLMConfigurationException When the profile cannot be resolved
     */
    public function chatClientFor(string $profileKey): AsyncChatLLMInterface
    {
        return ClientFactory::createChatClientForProfile($this->resolve($profileKey));
    }
}
