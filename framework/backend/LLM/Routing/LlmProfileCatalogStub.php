<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * Framework LLM profile catalog.
 *
 * Ships a single built-in `default` profile whose fields draw from the existing
 * global LLM_* env variables, reproducing the legacy ClientFactory selection as
 * one named profile. Projects override this stub (array_replace) to declare
 * their own keyed profiles, exactly as they override the env catalog.
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
        ];
    }
}
