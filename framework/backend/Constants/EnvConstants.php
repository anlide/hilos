<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * EnvConstants - Environment variable name constants.
 *
 * Defines all environment variable names used by the framework.
 * Centralized environment variable name management prevents typos and ensures consistency.
 * Using Pure Enum where the name IS the value.
 *
 * Read values through Hilos::$env:
 * Hilos::$env[EnvConstants::HILOS_DAEMON_HOST]
 */
enum EnvConstants
{
    /** @var string Daemon host for CLI connection */
    case HILOS_DAEMON_HOST;

    /** @var string HTTP status server host */
    case HTTP_STATUS_HOST;

    /** @var string HTTP status server port */
    case HTTP_STATUS_PORT;

    /** @var string HTTP status server: allow persistent connections (keep-alive); true|false */
    case HTTP_STATUS_KEEP_ALIVE;

    /** @var string Worker communication host */
    case WORKER_COMM_HOST;

    /** @var string Worker communication port */
    case WORKER_COMM_PORT;

    /** @var string CLI command channel host */
    case COMMAND_HOST;

    /** @var string CLI command channel port */
    case COMMAND_PORT;

    /** @var string Database host */
    case DB_HOST;

    /** @var string Database port */
    case DB_PORT;

    /** @var string Database name */
    case DB_NAME;

    /** @var string Database user */
    case DB_USER;

    /** @var string Database password */
    case DB_PASSWORD;

    /** @var string Database root password */
    case DB_ROOT_PASSWORD;

    /** @var string Database username (Laravel-style, used in demo) */
    case DB_USERNAME;

    /** @var string Database name (Laravel-style, used in demo) */
    case DB_DATABASE;

    /** @var string Secondary database host */
    case DB_SECONDARY_HOST;

    /** @var string Secondary database username */
    case DB_SECONDARY_USERNAME;

    /** @var string Secondary database password */
    case DB_SECONDARY_PASSWORD;

    /** @var string Secondary database name */
    case DB_SECONDARY_DATABASE;

    /** @var string Secondary database port */
    case DB_SECONDARY_PORT;

    /** @var string Daemon log file path */
    case DAEMON_LOG_FILE;

    /** @var string Daemon error log file path */
    case DAEMON_ERROR_LOG_FILE;

    /** @var string Docker network subnet */
    case DOCKER_NETWORK_SUBNET;

    /** @var string Docker daemon IP address */
    case DOCKER_DAEMON_IP;

    /** @var string Docker detection: 'true' when running in container */
    case DOCKER;

    /** @var string Terminal type (xterm, dumb, etc.) for TUI detection */
    case TERM;

    /** @var string WebSocket server host */
    case WEBSOCKET_HOST;

    /** @var string WebSocket server port */
    case WEBSOCKET_PORT;

    /** @var string Socket read buffer size in bytes */
    case SOCKET_READ_BUFFER_SIZE;

    /** @var string Minimum number of regular worker processes to run */
    case WORKER_MIN_REGULAR;

    /** @var string Minimum number of monopolistic worker processes to run */
    case WORKER_MIN_MONOPOLISTIC;

    /** @var string Maximum number of regular worker processes that can be started */
    case WORKER_MAX_REGULAR;

    /** @var string Minimum interval between daemon restarts after errors (in seconds) */
    case DAEMON_MIN_RESTART_INTERVAL;

    // ── LLM ────────────────────────────────────────────────────────────────

    /** @var string LLM chat provider: local | external */
    case LLM_CHAT_PROVIDER;

    /** @var string Local LLM base URL (e.g. Ollama) */
    case LLM_LOCAL_URL;

    /** @var string Local LLM chat model name */
    case LLM_LOCAL_CHAT_MODEL;

    /** @var string External LLM base URL (e.g. OpenAI) */
    case LLM_EXTERNAL_URL;

    /** @var string External LLM API key */
    case LLM_EXTERNAL_API_KEY;

    /** @var string External LLM chat model name */
    case LLM_EXTERNAL_CHAT_MODEL;

    // ── Chat moderation (demo) ──────────────────────────────────────────────

    /** @var string Moderation model name */
    case CHAT_MODERATION_MODEL;

    /** @var string Moderation API base URL */
    case CHAT_MODERATION_URL;

    /** @var string Moderation request timeout in seconds */
    case CHAT_MODERATION_TIMEOUT_SEC;

    /** @var string Moderation provider: local | external */
    case CHAT_MODERATION_PROVIDER;

    /** @var string Enable moderation for bot messages: 1|0, true|false (default: false) */
    case CHAT_MODERATION_BOTS;

    // ── Chat context analyzer (demo) ────────────────────────────────────────

    /** @var string Context analyzer model name */
    case CHAT_CONTEXT_ANALYZER_MODEL;

    /** @var string Context analyzer API base URL */
    case CHAT_CONTEXT_ANALYZER_URL;

    /** @var string Context analyzer request timeout in seconds */
    case CHAT_CONTEXT_ANALYZER_TIMEOUT_SEC;

    /** @var string Context analyzer provider: local | external */
    case CHAT_CONTEXT_ANALYZER_PROVIDER;

    // ── Chat bot (demo) ────────────────────────────────────────────────────────

    /** @var string Bot model name */
    case CHAT_BOT_MODEL;

    /** @var string Bot API base URL */
    case CHAT_BOT_URL;

    /** @var string Bot request timeout in seconds */
    case CHAT_BOT_TIMEOUT_SEC;

    /** @var string Bot provider: local | external */
    case CHAT_BOT_PROVIDER;

    /** @var string Chat language: ru, en (ISO 639-1). Bots respond in this language. */
    case CHAT_BOT_LANGUAGE;

    // ── Frontend (demo) ──────────────────────────────────────────────────────

    /** @var string Path to frontend dist for daemon static server */
    case FRONTEND_DIST_PATH;

    /** @var string Host for HTML file server */
    case FRONTEND_HTML_HOST;

    /** @var string Port for HTML file server */
    case FRONTEND_HTML_PORT;

    // ── Application environment ──────────────────────────────────────────────

    /**
     * Application environment. Use AppEnv enum values: prod, staging, dev, local, test.
     * Aliases accepted: production->prod, development->dev, etc.
     * When PROD or STAGING: database seeds are disabled.
     */
    case APP_ENV;

    /**
     * Build timestamp carried in the WebSocket handshake welcome frame.
     * Bumped at frontend build time; the frontend compares it on every
     * (re)connect and forces a page refresh on mismatch. 'dev' when unset.
     */
    case HILOS_BUILD_TIMESTAMP;

    /**
     * Name of the session-token cookie the daemon sets on the WebSocket
     * handshake (101) when the client has none. Override to rename it.
     */
    case HILOS_SESSION_COOKIE_NAME;

    /**
     * Whether the daemon issues the session-token cookie on the handshake.
     * Default true; set false to opt a project out of framework-issued cookies.
     */
    case HILOS_SESSION_COOKIE_ENABLED;

    /**
     * Whether the session-token cookie carries the Secure attribute. Default
     * false so it works over the plain-http dev stack; set true under TLS.
     */
    case HILOS_SESSION_COOKIE_SECURE;
}
