<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * EnvConstants - Environment variable name constants
 *
 * Defines all environment variable names used by the framework.
 * Centralized environment variable name management prevents typos and ensures consistency.
 * Using Pure Enum where the name IS the value.
 *
 * Access the variable name using ->value() method:
 * Env::get(EnvConstants::HILOS_DAEMON_HOST->value())
 */
enum EnvConstants
{
    /** @var string Daemon host for CLI connection */
    case HILOS_DAEMON_HOST;

    /** @var string HTTP status server host */
    case HTTP_STATUS_HOST;

    /** @var string HTTP status server port */
    case HTTP_STATUS_PORT;

    /** @var string Worker communication host */
    case WORKER_COMM_HOST;

    /** @var string Worker communication port */
    case WORKER_COMM_PORT;

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

    /** @var string Daemon log file path */
    case DAEMON_LOG_FILE;

    /** @var string Daemon error log file path */
    case DAEMON_ERROR_LOG_FILE;

    /** @var string Docker network subnet */
    case DOCKER_NETWORK_SUBNET;

    /** @var string Docker daemon IP address */
    case DOCKER_DAEMON_IP;

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

    /** @var string LLM image provider: local | external */
    case LLM_IMAGE_PROVIDER;

    /** @var string Local LLM base URL (e.g. Ollama) */
    case LLM_LOCAL_URL;

    /** @var string Local LLM chat model name */
    case LLM_LOCAL_CHAT_MODEL;

    /** @var string Local LLM image model name */
    case LLM_LOCAL_IMAGE_MODEL;

    /** @var string External LLM base URL (e.g. OpenAI) */
    case LLM_EXTERNAL_URL;

    /** @var string External LLM API key */
    case LLM_EXTERNAL_API_KEY;

    /** @var string External LLM chat model name */
    case LLM_EXTERNAL_CHAT_MODEL;

    /** @var string External LLM image model name */
    case LLM_EXTERNAL_IMAGE_MODEL;

    // ── Chat moderation (demo) ──────────────────────────────────────────────

    /** @var string Moderation model name */
    case CHAT_MODERATION_MODEL;

    /** @var string Moderation API base URL */
    case CHAT_MODERATION_URL;

    /** @var string Moderation request timeout in seconds */
    case CHAT_MODERATION_TIMEOUT_SEC;

    /** @var string Moderation provider: local | external */
    case CHAT_MODERATION_PROVIDER;
}
