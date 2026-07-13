<?php

declare(strict_types=1);

namespace Hilos\Environment;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\DatabaseConnectionDefaults;

/**
 * Framework default catalog for environment variables.
 */
final class EnvCatalogStub implements CatalogProviderInterface
{
    /**
     * Returns the framework environment catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return [
            EnvConstants::HILOS_DAEMON_HOST->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::HTTP_STATUS_HOST->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::HTTP_STATUS_PORT->name => self::required(EnvCatalogConstants::TYPE_INTEGER),
            EnvConstants::HTTP_STATUS_KEEP_ALIVE->name => self::entry(
                EnvCatalogConstants::TYPE_BOOLEAN,
                true,
                emptyIsMissing: true,
            ),
            EnvConstants::WORKER_COMM_HOST->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::WORKER_COMM_PORT->name => self::required(EnvCatalogConstants::TYPE_INTEGER),
            EnvConstants::COMMAND_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, '0.0.0.0', emptyIsMissing: true),
            EnvConstants::COMMAND_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 8094, emptyIsMissing: true),
            EnvConstants::DB_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::HOST, emptyIsMissing: true),
            EnvConstants::DB_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, DatabaseConnectionDefaults::PORT, emptyIsMissing: true),
            EnvConstants::DB_NAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'hilos_db', emptyIsMissing: true),
            EnvConstants::DB_USER->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'hilos_user', emptyIsMissing: true),
            EnvConstants::DB_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::PASSWORD),
            EnvConstants::DB_ROOT_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::DB_USERNAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::USER, emptyIsMissing: true),
            EnvConstants::DB_DATABASE->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::DB_SECONDARY_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::HOST, emptyIsMissing: true),
            EnvConstants::DB_SECONDARY_USERNAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::USER, emptyIsMissing: true),
            EnvConstants::DB_SECONDARY_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::PASSWORD),
            EnvConstants::DB_SECONDARY_DATABASE->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'hilos_secondary'),
            EnvConstants::DB_SECONDARY_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, DatabaseConnectionDefaults::PORT, emptyIsMissing: true),
            EnvConstants::DAEMON_LOG_FILE->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::DAEMON_ERROR_LOG_FILE->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::DOCKER_NETWORK_SUBNET->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                '172.25.0.0/16',
                emptyIsMissing: true,
            ),
            EnvConstants::DOCKER_DAEMON_IP->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                '172.25.0.10',
                emptyIsMissing: true,
            ),
            EnvConstants::DOCKER->name => self::entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
            EnvConstants::TERM->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WEBSOCKET_HOST->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::WEBSOCKET_PORT->name => self::required(EnvCatalogConstants::TYPE_INTEGER),
            EnvConstants::SOCKET_READ_BUFFER_SIZE->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                8192,
                emptyIsMissing: true,
            ),
            EnvConstants::WORKER_MIN_REGULAR->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 3, emptyIsMissing: true),
            EnvConstants::WORKER_MIN_MONOPOLISTIC->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                2,
                emptyIsMissing: true,
            ),
            EnvConstants::WORKER_MAX_REGULAR->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 10, emptyIsMissing: true),
            EnvConstants::DAEMON_MIN_RESTART_INTERVAL->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                20,
                emptyIsMissing: true,
            ),
            EnvConstants::LLM_CHAT_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::LLM_LOCAL_URL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_LOCAL_URL,
                emptyIsMissing: true,
            ),
            EnvConstants::LLM_LOCAL_CHAT_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_LOCAL_CHAT_MODEL,
                emptyIsMissing: true,
            ),
            EnvConstants::LLM_EXTERNAL_URL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_EXTERNAL_URL,
                emptyIsMissing: true,
            ),
            EnvConstants::LLM_EXTERNAL_API_KEY->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::LLM_EXTERNAL_CHAT_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_EXTERNAL_CHAT_MODEL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_LOCAL_CHAT_MODEL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_URL->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CHAT_MODERATION_TIMEOUT_SEC->name => self::entry(
                EnvCatalogConstants::TYPE_FLOAT,
                LLMConstants::DEFAULT_TIMEOUT_SEC,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_MODERATION_BOTS->name => self::entry(
                EnvCatalogConstants::TYPE_BOOLEAN,
                false,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_LOCAL_CHAT_MODEL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_URL->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC->name => self::entry(
                EnvCatalogConstants::TYPE_FLOAT,
                LLMConstants::DEFAULT_TIMEOUT_SEC,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_CONTEXT_ANALYZER_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_MODEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::DEFAULT_LOCAL_CHAT_MODEL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_URL->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CHAT_BOT_TIMEOUT_SEC->name => self::entry(
                EnvCatalogConstants::TYPE_FLOAT,
                LLMConstants::DEFAULT_TIMEOUT_SEC,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_PROVIDER->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LLMConstants::PROVIDER_LOCAL,
                emptyIsMissing: true,
            ),
            EnvConstants::CHAT_BOT_LANGUAGE->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'en', emptyIsMissing: true),
            EnvConstants::FRONTEND_DIST_PATH->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::FRONTEND_HTML_HOST->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                '0.0.0.0',
                emptyIsMissing: true,
            ),
            EnvConstants::FRONTEND_HTML_PORT->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                8093,
                emptyIsMissing: true,
            ),
            EnvConstants::APP_ENV->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                AppEnv::DEV->value,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_BUILD_TIMESTAMP->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                'dev',
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_SESSION_COOKIE_NAME->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                'hilos_session_token',
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_SESSION_COOKIE_ENABLED->name => self::entry(
                EnvCatalogConstants::TYPE_BOOLEAN,
                true,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_SESSION_COOKIE_SECURE->name => self::entry(
                EnvCatalogConstants::TYPE_BOOLEAN,
                false,
                emptyIsMissing: true,
            ),
            EnvConstants::CLUSTER_ENABLED->name => self::entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
            EnvConstants::CLUSTER_NODE_ID->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_NODE_ROLE->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_NODE_CAPABILITIES->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_PEER_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, '0.0.0.0', emptyIsMissing: true),
            EnvConstants::CLUSTER_PEER_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 8095, emptyIsMissing: true),
            EnvConstants::CLUSTER_SEEDS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
        ];
    }

    /**
     * @param string $type Catalog value type
     * @return array<string, mixed> Required catalog entry
     */
    private static function required(string $type): array
    {
        return [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => $type,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => true,
        ];
    }

    /**
     * @param string $type Catalog value type
     * @param mixed $default Default value
     * @param bool $emptyIsMissing Whether empty values fall back to defaults
     * @return array<string, mixed> Catalog entry
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
