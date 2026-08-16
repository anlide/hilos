<?php

declare(strict_types=1);

namespace Hilos\Telegram;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * TelegramGatewayConfig - what the Gateway needs to be spoken to (HIL-492).
 *
 * The recipe lives in the framework and only the credentials live in env, which is
 * the rule for every external provider here: a project states its token, and the
 * endpoints, field names and response shape are the framework's business - so
 * pointing a project at Telegram is a token, not a manual.
 *
 * The endpoint URL is configurable for exactly one reason, and it is worth naming so
 * it is not mistaken for flexibility: the test stand runs its own mock Gateway, and
 * a suite that cannot redirect the transport can only test it by not testing it. In
 * production it is the default and nobody sets it.
 *
 * An empty token is a legitimate, deliberate state - it means the project registered
 * the channel but has not configured it, and {@see TelegramGatewayClient} then reports
 * every number unreachable instead of failing. The cost of that lands on the messenger
 * alone; the SMS button beside it keeps working, which is the correct outcome for a
 * person trying to sign in.
 */
final readonly class TelegramGatewayConfig
{
    /** Gateway base URL used when the project names none. */
    public const string DEFAULT_ENDPOINT_URL = 'https://gatewayapi.telegram.org';

    /**
     * @param string $endpointUrl Gateway base URL every call is built against
     * @param string $accessToken Gateway access token; empty leaves the channel unconfigured
     * @param string $senderUsername Sender username shown on the message; empty lets the Gateway pick
     * @param int $timeoutMs Per-request network timeout in milliseconds
     */
    public function __construct(
        public string $endpointUrl,
        public string $accessToken,
        public string $senderUsername,
        public int $timeoutMs,
    ) {
    }

    /**
     * Reads the configuration off the environment.
     *
     * @return self Resolved Gateway configuration
     * @throws EnvException When a Gateway env key is missing, outside the catalog, or of the wrong type
     */
    public static function resolve(): self
    {
        $endpointUrl = Hilos::$env->string(EnvConstants::TELEGRAM_GATEWAY_ENDPOINT_URL);

        return new self(
            endpointUrl: $endpointUrl === '' ? self::DEFAULT_ENDPOINT_URL : $endpointUrl,
            accessToken: Hilos::$env->string(EnvConstants::TELEGRAM_GATEWAY_TOKEN),
            senderUsername: Hilos::$env->string(EnvConstants::TELEGRAM_GATEWAY_SENDER_USERNAME),
            timeoutMs: Hilos::$env->int(EnvConstants::TELEGRAM_GATEWAY_TIMEOUT_MS),
        );
    }

    /**
     * Whether the Gateway can be called at all.
     *
     * @return bool True when a token is configured
     */
    public function isConfigured(): bool
    {
        return $this->accessToken !== '';
    }
}
