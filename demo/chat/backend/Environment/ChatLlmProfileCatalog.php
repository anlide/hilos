<?php

declare(strict_types=1);

namespace Demo\Chat\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\LLM\Routing\LlmProfileCatalogConstants;
use Hilos\LLM\Routing\LlmProfileCatalogStub;

/**
 * Chat demo LLM profile catalog.
 *
 * Declares one named profile per chat LLM role (bot / moderation / analyzer),
 * each drawing from that role's CHAT_* env variables, on top of the framework
 * `default` profile. The per-role local URL falls back to LLM_LOCAL_URL when
 * unset; the external provider uses the same per-role model and the global
 * external URL / API key. Admin-settings overrides land in HIL-262 via the
 * router's override source.
 */
final class ChatLlmProfileCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Profiles keyed by profile key
     */
    public static function getCatalog(): array
    {
        return array_replace(LlmProfileCatalogStub::getCatalog(), [
            'chat.bot' => self::role(
                EnvConstants::CHAT_BOT_PROVIDER,
                EnvConstants::CHAT_BOT_URL,
                EnvConstants::CHAT_BOT_MODEL,
                EnvConstants::CHAT_BOT_TIMEOUT_SEC,
            ),
            'chat.moderation' => self::role(
                EnvConstants::CHAT_MODERATION_PROVIDER,
                EnvConstants::CHAT_MODERATION_URL,
                EnvConstants::CHAT_MODERATION_MODEL,
                EnvConstants::CHAT_MODERATION_TIMEOUT_SEC,
            ),
            'chat.analyzer' => self::role(
                EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER,
                EnvConstants::CHAT_CONTEXT_ANALYZER_URL,
                EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL,
                EnvConstants::CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC,
            ),
        ]);
    }

    /**
     * Builds one role profile entry from its CHAT_* env keys.
     *
     * @param EnvConstants $providerEnv Provider env key ('local' | 'external')
     * @param EnvConstants $localUrlEnv Per-role local URL env key (falls back to LLM_LOCAL_URL)
     * @param EnvConstants $modelEnv Per-role model env key (used for both providers)
     * @param EnvConstants $timeoutEnv Per-role timeout env key
     * @return array<string, mixed> Profile catalog entry
     */
    private static function role(
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
