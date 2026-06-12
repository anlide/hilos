<?php

declare(strict_types=1);

namespace Demo\Chat\Environment;

use Demo\Chat\Constants\ChatEnvConstants;
use Demo\Chat\Constants\ChatLLMConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;

/**
 * Chat demo environment catalog.
 */
final class ChatEnvCatalog implements CatalogProviderInterface
{
    /**
     * Returns the chat demo environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(EnvCatalogStub::getCatalog(), [
            EnvConstants::DB_DATABASE->name => self::stringEntry('hilos_demo', emptyIsMissing: true),
            EnvConstants::CHAT_MODERATION_MODEL->name => self::stringEntry(
                ChatLLMConstants::MODEL_MODERATION,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_PROVIDER->name => self::stringEntry(
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL->name => self::stringEntry(
                ChatLLMConstants::MODEL_CONTEXT_ANALYZER,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER->name => self::stringEntry(
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_MODEL->name => self::stringEntry(
                ChatLLMConstants::MODEL_BOT,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_PROVIDER->name => self::stringEntry(
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_LANGUAGE->name => self::stringEntry('ru', emptyIsMissing: true),
            ChatEnvConstants::CHAT_FILES_QUARANTINE_DIR => self::stringEntry(''),
            ChatEnvConstants::CHAT_FILES_PUBLISHED_DIR => self::stringEntry(''),
        ]);
    }

    /**
     * @param string $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to defaults
     * @return array<string, mixed> Catalog entry for a string-typed variable
     */
    private static function stringEntry(string $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
