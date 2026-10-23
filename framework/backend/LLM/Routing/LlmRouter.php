<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\Exception\LLMConfigurationException;

/**
 * Resolves named LLM profiles and builds chat clients for them.
 *
 * The single policy layer above {@see ClientFactory}: it turns a profile key
 * into an immutable {@see LlmProfile} (provider explicit, resolved from the
 * env-backed catalog plus the runtime overrides), then builds the async chat
 * client for it. Replaces the global LLM_CHAT_PROVIDER switch read at multiple
 * call sites; that switch is now the `default` profile's provider env.
 *
 * The catalog it resolves from is the framework's {@see LlmProfileCatalogStub}
 * with the project's own catalog on top, so the built-in keys are declared in
 * every project. The overrides come in two layers: the project's optional source
 * first, then {@see FrameworkLlmProfileOverrideSource}, which the router always
 * applies, so the framework settings of a built-in key have the last word.
 */
class LlmRouter
{
    /** @var class-string<CatalogProviderInterface> Profile catalog provider class */
    private string $catalogClass;

    private ?LlmProfileOverrideSource $overrides;

    private FrameworkLlmProfileOverrideSource $frameworkOverrides;

    /**
     * @param class-string<CatalogProviderInterface> $catalogClass Project profile catalog provider class,
     *   folded over the framework catalog
     * @param ?LlmProfileOverrideSource $overrides Project runtime override source, or null when the
     *   project overrides nothing; the framework override runs either way
     */
    public function __construct(
        string $catalogClass = LlmProfileCatalogStub::class,
        ?LlmProfileOverrideSource $overrides = null,
    ) {
        $this->catalogClass = $catalogClass;
        $this->overrides = $overrides;
        $this->frameworkOverrides = new FrameworkLlmProfileOverrideSource();
    }

    /**
     * Exposes the profile catalog: keys plus each entry's env-variable map.
     *
     * The single source of catalog knowledge for callers that need to enumerate
     * profiles or read an entry's env-variable names (e.g. the `llm:ping` CLI),
     * so no code re-reads the catalog provider class directly. The framework
     * profiles lie under the project's own, so a project catalog that declares
     * only its keys still resolves the built-in ones, and an entry the project
     * declares under a built-in key replaces it.
     *
     * @return array<string, array<string, mixed>> Profiles keyed by profile key
     */
    public function catalog(): array
    {
        return array_replace(LlmProfileCatalogStub::getCatalog(), ($this->catalogClass)::getCatalog());
    }

    /**
     * Resolves a profile key to its effective profile, applying the overrides.
     *
     * The project's override source runs first, the framework's last. The
     * framework's reads its settings afresh on every call, so the next resolve
     * after a saved change already returns the changed built-in profile.
     *
     * @param string $profileKey Profile key, e.g. 'default'
     * @return LlmProfile Effective profile (base plus the runtime overrides)
     * @throws LLMConfigurationException When the key is not declared, the provider
     *   is invalid, or the external provider is selected without an API key
     * @throws EnvException When an env variable the profile names is missing, outside
     *   the catalog, or of the wrong type
     * @throws SettingException When a profile override cannot read its setting
     */
    public function resolve(string $profileKey): LlmProfile
    {
        $base = $this->resolveBase($profileKey);

        return $this->frameworkOverrides->override($this->overrides?->override($base) ?? $base);
    }

    /**
     * Resolves a profile key to its env-backed profile, before any override.
     *
     * The pre-override half of {@see resolve()}: it turns catalog plus env into an
     * immutable profile without consulting either runtime override source. Kept
     * public so an operator tool can show what env resolves to versus what the
     * overrides changed, from one code path rather than two.
     *
     * @param string $profileKey Profile key, e.g. 'default'
     * @return LlmProfile Env-backed profile, before any runtime override
     * @throws LLMConfigurationException When the key is not declared, the provider
     *   is invalid, or the external provider is selected without an API key
     *
     * @throws EnvException When an env variable the profile names is missing, outside
     *   the catalog, or of the wrong type
     */
    public function resolveBase(string $profileKey): LlmProfile
    {
        $catalog = $this->catalog();
        if (!array_key_exists($profileKey, $catalog)) {
            throw new LLMConfigurationException("LLM profile '{$profileKey}' is not declared in the catalog");
        }
        $entry = $catalog[$profileKey];

        $provider = LlmProvider::fromValue(Hilos::$env[$entry[LlmProfileCatalogConstants::PROVIDER_ENV]]->string());

        if ($provider === LlmProvider::EXTERNAL) {
            $url = Hilos::$env[$entry[LlmProfileCatalogConstants::EXTERNAL_URL_ENV]]->string();
            $model = Hilos::$env[$entry[LlmProfileCatalogConstants::EXTERNAL_MODEL_ENV]]->string();
            $apiKey = Hilos::$env[$entry[LlmProfileCatalogConstants::API_KEY_ENV]]->string();
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
            $model = Hilos::$env[$entry[LlmProfileCatalogConstants::LOCAL_MODEL_ENV]]->string();
            $apiKey = null;
        }

        $timeoutEnv = $entry[LlmProfileCatalogConstants::TIMEOUT_ENV] ?? null;
        $timeoutSec = $timeoutEnv !== null
            ? Hilos::$env[$timeoutEnv]->float()
            : LLMConstants::DEFAULT_TIMEOUT_SEC;

        return new LlmProfile(
            $profileKey,
            $provider,
            $url,
            $model,
            $apiKey,
            $timeoutSec,
            $entry[LlmProfileCatalogConstants::PLACEMENT] ?? null,
        );
    }

    /**
     * Builds (or reuses) an async chat client for a resolved profile.
     *
     * @param string $profileKey Profile key, e.g. 'default'
     * @return AsyncChatLLMInterface Async chat client configured for the profile
     * @throws LLMConfigurationException When the profile cannot be resolved
     * @throws EnvException When an env variable the profile names is missing, outside
     *   the catalog, or of the wrong type
     * @throws SettingException When a profile override cannot read its setting
     */
    public function chatClientFor(string $profileKey): AsyncChatLLMInterface
    {
        return ClientFactory::createChatClientForProfile($this->resolve($profileKey));
    }
}
