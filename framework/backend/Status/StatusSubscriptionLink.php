<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/** Validated public address captured before issuing a subscription challenge. */
final readonly class StatusSubscriptionLink
{
    private const string UNAVAILABLE = 'Status subscriptions are unavailable. Try again later.';

    /**
     * @param string $statusUrl Absolute public /status address, without credentials, query or fragment
     * @throws ValidationException When the configured address cannot safely carry subscription links
     */
    public function __construct(public string $statusUrl)
    {
        $parts = parse_url($statusUrl);
        if ($parts === false || filter_var($statusUrl, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f]/', $statusUrl) === 1
            || !isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || !isset($parts['host']) || ($parts['path'] ?? null) !== '/status'
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new ValidationException(self::UNAVAILABLE);
        }
    }

    /**
     * @return self Validated configured address, independent of an incoming Host header
     * @throws EnvException When the environment entry is unreadable
     * @throws ValidationException When the public Status address is missing or invalid
     */
    public static function fromEnvironment(): self
    {
        if (Hilos::$env === null) {
            throw new ValidationException(self::UNAVAILABLE);
        }
        return new self(Hilos::$env[EnvConstants::HILOS_STATUS_URL]->string());
    }

    /**
     * @param string $token Raw confirmation secret
     * @return string Fragment-only confirmation link for the browser relay
     * @throws ValidationException When the secret has an invalid format
     */
    public function confirmUrl(string $token): string
    {
        return $this->fragment('status-confirm', $token);
    }

    /**
     * @param string $token Raw unsubscribe secret
     * @return string Fragment-only unsubscribe link for the browser relay
     * @throws ValidationException When the secret has an invalid format
     */
    public function unsubscribeUrl(string $token): string
    {
        return $this->fragment('status-unsubscribe', $token);
    }

    /**
     * @param string $name Approved fragment operation
     * @param string $token Raw link secret, never put in the query or path
     * @return string Public link
     * @throws ValidationException When the secret has an invalid format
     */
    private function fragment(string $name, string $token): string
    {
        if (preg_match('/^[a-fA-F0-9]{64}$/D', $token) !== 1) {
            throw new ValidationException('This subscription link is invalid. Request a new link.');
        }
        return $this->statusUrl . '#' . $name . '=' . $token;
    }
}
