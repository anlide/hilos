<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Moderation\ModerationLlmConstants;

/**
 * Framework LLM profile catalog.
 *
 * Ships the built-in profiles: `default`, whose fields draw from the existing global LLM_* env
 * variables and reproduce the legacy ClientFactory selection as one named profile, and the two
 * profiles of the common moderator, `hilos.moderation.text` and `hilos.moderation.image`, each
 * drawing from its own HILOS_MODERATION_* role env. The router folds this catalog under a
 * project's own one, so the built-in keys stay declared in a project that names its own catalog.
 */
final class LlmProfileCatalogStub implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Profiles keyed by profile key
     */
    public static function getCatalog(): array
    {
        return [
            LlmProfileCatalogConstants::DEFAULT_PROFILE => [
                LlmProfileCatalogConstants::PROVIDER_ENV => EnvConstants::LLM_CHAT_PROVIDER->name,
                LlmProfileCatalogConstants::LOCAL_URL_ENV => EnvConstants::LLM_LOCAL_URL->name,
                LlmProfileCatalogConstants::LOCAL_URL_FALLBACK_ENV => null,
                LlmProfileCatalogConstants::LOCAL_MODEL_ENV => EnvConstants::LLM_LOCAL_CHAT_MODEL->name,
                LlmProfileCatalogConstants::EXTERNAL_URL_ENV => EnvConstants::LLM_EXTERNAL_URL->name,
                LlmProfileCatalogConstants::EXTERNAL_MODEL_ENV => EnvConstants::LLM_EXTERNAL_CHAT_MODEL->name,
                LlmProfileCatalogConstants::API_KEY_ENV => EnvConstants::LLM_EXTERNAL_API_KEY->name,
                LlmProfileCatalogConstants::TIMEOUT_ENV => null,
                LlmProfileCatalogConstants::PLACEMENT => null,
            ],
            ModerationLlmConstants::PROFILE_TEXT => self::moderationRole(
                EnvConstants::HILOS_MODERATION_TEXT_PROVIDER,
                EnvConstants::HILOS_MODERATION_TEXT_URL,
                EnvConstants::HILOS_MODERATION_TEXT_MODEL,
                EnvConstants::HILOS_MODERATION_TEXT_TIMEOUT_SEC,
            ),
            ModerationLlmConstants::PROFILE_IMAGE => self::moderationRole(
                EnvConstants::HILOS_MODERATION_IMAGE_PROVIDER,
                EnvConstants::HILOS_MODERATION_IMAGE_URL,
                EnvConstants::HILOS_MODERATION_IMAGE_MODEL,
                EnvConstants::HILOS_MODERATION_IMAGE_TIMEOUT_SEC,
            ),
        ];
    }

    /**
     * Builds one moderator role entry from its HILOS_MODERATION_* env keys.
     *
     * One model env serves both providers; the external endpoint and its API key are the global
     * ones, and an empty role URL falls back to LLM_LOCAL_URL.
     *
     * @param EnvConstants $providerEnv Provider env key ('local' | 'external')
     * @param EnvConstants $localUrlEnv Role local URL env key (falls back to LLM_LOCAL_URL)
     * @param EnvConstants $modelEnv Role model env key (used for both providers)
     * @param EnvConstants $timeoutEnv Role timeout env key
     * @return array<string, mixed> Profile catalog entry
     */
    private static function moderationRole(
        EnvConstants $providerEnv,
        EnvConstants $localUrlEnv,
        EnvConstants $modelEnv,
        EnvConstants $timeoutEnv,
    ): array {
        return [
            LlmProfileCatalogConstants::PROVIDER_ENV => $providerEnv->name,
            LlmProfileCatalogConstants::LOCAL_URL_ENV => $localUrlEnv->name,
            LlmProfileCatalogConstants::LOCAL_URL_FALLBACK_ENV => EnvConstants::LLM_LOCAL_URL->name,
            LlmProfileCatalogConstants::LOCAL_MODEL_ENV => $modelEnv->name,
            LlmProfileCatalogConstants::EXTERNAL_URL_ENV => EnvConstants::LLM_EXTERNAL_URL->name,
            LlmProfileCatalogConstants::EXTERNAL_MODEL_ENV => $modelEnv->name,
            LlmProfileCatalogConstants::API_KEY_ENV => EnvConstants::LLM_EXTERNAL_API_KEY->name,
            LlmProfileCatalogConstants::TIMEOUT_ENV => $timeoutEnv->name,
            LlmProfileCatalogConstants::PLACEMENT => null,
        ];
    }
}
