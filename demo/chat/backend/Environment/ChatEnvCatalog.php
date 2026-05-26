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
            EnvConstants::DB_DATABASE->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                'hilos_demo',
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                ChatLLMConstants::MODEL_MODERATION,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                ChatLLMConstants::MODEL_CONTEXT_ANALYZER,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                ChatLLMConstants::MODEL_BOT,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_LANGUAGE->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                'ru',
                emptyIsMissing: true,
            ),
            ChatEnvConstants::CHAT_FILES_QUARANTINE_DIR => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            ChatEnvConstants::CHAT_FILES_PUBLISHED_DIR => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $type, mixed $default, bool $emptyIsMissing = false): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => $default,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => $emptyIsMissing,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => false,
        ];
    }
}
