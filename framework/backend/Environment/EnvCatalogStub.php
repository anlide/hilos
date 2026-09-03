<?php

declare(strict_types=1);

namespace Hilos\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LLMConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Mail\SmtpSecurity;
use Hilos\Utils\LogLevel;

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
            EnvConstants::COMMAND_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, '127.0.0.1', emptyIsMissing: true),
            EnvConstants::COMMAND_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 8094, emptyIsMissing: true),
            EnvConstants::DB_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::HOST, emptyIsMissing: true),
            EnvConstants::DB_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, DatabaseConnectionDefaults::PORT, emptyIsMissing: true),
            EnvConstants::DB_NAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'hilos_db', emptyIsMissing: true),
            EnvConstants::DB_USER->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'hilos_user', emptyIsMissing: true),
            EnvConstants::DB_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::PASSWORD),
            EnvConstants::DB_ROOT_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::DB_USERNAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::USER, emptyIsMissing: true),
            EnvConstants::DB_DATABASE->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::DB_SECONDARY_HOST->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                DatabaseConnectionDefaults::HOST,
                emptyIsMissing: true,
            ),
            EnvConstants::DB_SECONDARY_USERNAME->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                DatabaseConnectionDefaults::USER,
                emptyIsMissing: true,
            ),
            EnvConstants::DB_SECONDARY_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, DatabaseConnectionDefaults::PASSWORD),
            EnvConstants::DB_SECONDARY_DATABASE->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'hilos_secondary'),
            EnvConstants::HILOS_DB_REHYDRATE_TIMEOUT->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                30,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_PROTECTED_MODE_QUIESCE_TIMEOUT->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                30,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_PROTECTED_MODE_SILENCE_TIMEOUT->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                600,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_PROTECTED_MODE_ALERT_INTERVAL->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                900,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_PROTECTED_MODE_ALERT_EMAILS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::DB_SECONDARY_PORT->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                DatabaseConnectionDefaults::PORT,
                emptyIsMissing: true,
            ),
            EnvConstants::DAEMON_LOG_FILE->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::DAEMON_ERROR_LOG_FILE->name => self::required(EnvCatalogConstants::TYPE_STRING),
            EnvConstants::DOCKER_NETWORK_SUBNET->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                '10.190.0.0/16',
                emptyIsMissing: true,
            ),
            EnvConstants::DOCKER_DAEMON_IP->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                '10.190.0.10',
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
            EnvConstants::DAEMON_FAILED_START_THRESHOLD->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                3,
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
            // Required, with no default on purpose (HIL-566): a node that never sets APP_ENV
            // used to call itself `dev`, which is the one answer that opens every test-only
            // command on a port that authenticates nobody. Refusing to start instead costs a
            // named variable in each deployment and says exactly what is missing.
            EnvConstants::APP_ENV->name => self::required(EnvCatalogConstants::TYPE_STRING),
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
            EnvConstants::HILOS_SESSION_COOKIE_MAX_AGE->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                730 * 24 * 60 * 60,
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_PENDING_REGISTRATION_SWEEP_CRON->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                '*/5 * * * *',
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_VERIFICATION_CODE_LENGTH->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 6, emptyIsMissing: true),
            EnvConstants::HILOS_VERIFICATION_TTL_SEC->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 900, emptyIsMissing: true),
            EnvConstants::HILOS_VERIFICATION_MAX_ATTEMPTS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 5, emptyIsMissing: true),
            EnvConstants::HILOS_VERIFICATION_RESEND_COOLDOWN_SEC->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 60, emptyIsMissing: true),
            EnvConstants::HILOS_VERIFICATION_SEND_WINDOW_SEC->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 3600, emptyIsMissing: true),
            EnvConstants::HILOS_VERIFICATION_SEND_CAP->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 5, emptyIsMissing: true),
            EnvConstants::HILOS_VERIFICATION_SEND_CAP_SMS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 3, emptyIsMissing: true),
            EnvConstants::HILOS_MAGIC_LINK_URL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                'http://localhost:5173/auth/magic',
                emptyIsMissing: true,
            ),
            EnvConstants::HILOS_AUTH_THROTTLE_ENABLED->name => self::entry(EnvCatalogConstants::TYPE_BOOLEAN, true, emptyIsMissing: true),
            EnvConstants::HILOS_AUTH_THROTTLE_WINDOW->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 60, emptyIsMissing: true),
            EnvConstants::HILOS_AUTH_THROTTLE_MAX_SESSION->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 10, emptyIsMissing: true),
            EnvConstants::HILOS_AUTH_THROTTLE_MAX_IP->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 30, emptyIsMissing: true),
            EnvConstants::HILOS_AUTH_THROTTLE_STEPS->name => self::entry(EnvCatalogConstants::TYPE_STRING, '30,120,600,3600', emptyIsMissing: true),
            EnvConstants::HILOS_AUTH_THROTTLE_VERDICT_TIMEOUT_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 1000, emptyIsMissing: true),
            EnvConstants::HILOS_TRUSTED_PROXIES->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::BACKUP_DIR->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::BACKUP_ENABLED->name => self::entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
            EnvConstants::BACKUP_RESTORE_TIMEOUT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 3600, emptyIsMissing: true),
            EnvConstants::BACKUP_SHIP_TARGET->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::BACKUP_SHIP_SSH_KEY->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::BACKUP_SHIP_SSH_KNOWN_HOSTS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::BACKUP_SHIP_ENCRYPT_RECIPIENTS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::BACKUP_SHIP_TIMEOUT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 3600, emptyIsMissing: true),
            EnvConstants::CLUSTER_ENABLED->name => self::entry(EnvCatalogConstants::TYPE_BOOLEAN, false, emptyIsMissing: true),
            EnvConstants::CLUSTER_NODE_ID->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_NODE_ROLE->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_NODE_CAPABILITIES->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_PEER_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, '0.0.0.0', emptyIsMissing: true),
            EnvConstants::CLUSTER_PEER_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 8095, emptyIsMissing: true),
            EnvConstants::CLUSTER_SEEDS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_PEER_ADVERTISE->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_MASTER_SET->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::CLUSTER_ELECTION_TIMEOUT_MIN_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 1500, emptyIsMissing: true),
            EnvConstants::CLUSTER_ELECTION_TIMEOUT_MAX_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 3000, emptyIsMissing: true),
            EnvConstants::CLUSTER_HEARTBEAT_INTERVAL_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 500, emptyIsMissing: true),
            EnvConstants::CLUSTER_SLAVE_WORK_GRACE_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 6000, emptyIsMissing: true),
            EnvConstants::CLUSTER_LINK_KEEPALIVE_INTERVAL_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 1000, emptyIsMissing: true),
            EnvConstants::CLUSTER_LINK_TIMEOUT_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 5000, emptyIsMissing: true),
            EnvConstants::CLUSTER_FAILOVER_GRACE_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 8000, emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_RP_ID->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'localhost', emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_RP_NAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'Hilos', emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_ORIGIN->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'http://localhost', emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_CHALLENGE_TTL_SEC->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 300, emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_USER_VERIFICATION->name => self::entry(EnvCatalogConstants::TYPE_STRING, 'preferred', emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_TIMEOUT_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 60000, emptyIsMissing: true),
            EnvConstants::HILOS_WEBAUTHN_CHALLENGE_SECRET->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_TRANSPORT->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_SMTP_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_SMTP_PORT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 587, emptyIsMissing: true),
            EnvConstants::MAIL_SMTP_SECURITY->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                SmtpSecurity::STARTTLS->value,
                emptyIsMissing: true,
            ),
            EnvConstants::MAIL_SMTP_USERNAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_SMTP_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_FROM_ADDRESS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_FROM_NAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::MAIL_TIMEOUT_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 10000, emptyIsMissing: true),
            EnvConstants::MAIL_WORKER_COUNT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 1, emptyIsMissing: true),
            EnvConstants::MAIL_MAX_CONCURRENT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 4, emptyIsMissing: true),
            EnvConstants::MAIL_FILE_DIR->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WATCHDOG_ALERT_SMTP_HOST->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WATCHDOG_ALERT_SMTP_PORT->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                587,
                emptyIsMissing: true,
            ),
            EnvConstants::WATCHDOG_ALERT_SMTP_SECURITY->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                SmtpSecurity::STARTTLS->value,
                emptyIsMissing: true,
            ),
            EnvConstants::WATCHDOG_ALERT_SMTP_USERNAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WATCHDOG_ALERT_SMTP_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WATCHDOG_ALERT_FROM_ADDRESS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WATCHDOG_ALERT_TO_ADDRESS->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::WATCHDOG_ALERT_TIMEOUT_MS->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                5000,
                emptyIsMissing: true,
            ),
            EnvConstants::TELEGRAM_GATEWAY_TOKEN->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::TELEGRAM_GATEWAY_ENDPOINT_URL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                'https://gatewayapi.telegram.org',
                emptyIsMissing: true,
            ),
            EnvConstants::TELEGRAM_GATEWAY_SENDER_USERNAME->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::TELEGRAM_GATEWAY_TIMEOUT_MS->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                5000,
                emptyIsMissing: true,
            ),
            EnvConstants::SMS_PROVIDER->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::SMS_ENDPOINT_URL->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::SMS_FROM->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::SMS_API_KEY->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::SMS_API_PASSWORD->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::SMS_TIMEOUT_MS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 10000, emptyIsMissing: true),
            EnvConstants::SMS_WORKER_COUNT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 1, emptyIsMissing: true),
            EnvConstants::VAPID_PUBLIC->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::VAPID_PRIVATE->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::VAPID_SUBJECT->name => self::entry(EnvCatalogConstants::TYPE_STRING, ''),
            EnvConstants::PUSH_WORKER_COUNT->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 1, emptyIsMissing: true),
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 0, emptyIsMissing: true),
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 0, emptyIsMissing: true),
            EnvConstants::LOG_ROTATION_CRON->name => self::entry(EnvCatalogConstants::TYPE_STRING, '', emptyIsMissing: true),
            EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name => self::entry(EnvCatalogConstants::TYPE_INTEGER, 20, emptyIsMissing: true),
            EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                2592000,
                emptyIsMissing: true,
            ),
            EnvConstants::LOG_TAKEOUT_UNDO_WINDOW_SECONDS->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                LogSettingsCatalog::TAKEOUT_UNDO_WINDOW_FALLBACK_SECONDS,
                emptyIsMissing: true,
            ),
            EnvConstants::LOG_WRITE_LEVEL->name => self::entry(
                EnvCatalogConstants::TYPE_STRING,
                LogLevel::Info->value,
                emptyIsMissing: true,
            ),
            EnvConstants::LOG_INDEX_PUSH_INTERVAL_MS->name => self::entry(
                EnvCatalogConstants::TYPE_INTEGER,
                5000,
                emptyIsMissing: true,
            ),
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
