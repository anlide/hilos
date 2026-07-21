<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * OAuthResultSignalData - the OAuth login failure/timeout signal (HIL-281).
 *
 * The one new signal mechanism B adds: it is delivered WS_USER to the initiating
 * connection's accept key when the async token/userinfo exchange fails, times out,
 * or resolves no subject. Success needs no signal of its own — it rides HIL-161's
 * existing session/currentUser fan-out — so this carries only the failure arm: the
 * SPA callback surface, which shows a spinner after `oauthCallback`, resolves on
 * EITHER a currentUser update (login) OR this signal ("OAuth login failed").
 *
 * The {@see reason} is a stable, non-sensitive code for the surface to render; the
 * network/provider detail stays in the agent log, never on the wire (HIL-281 keeps
 * post-I/O failures generic).
 */
final class OAuthResultSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /** Generic failure reason surfaced to the client (provider/network detail is logged, not sent). */
    public const string REASON_LOGIN_FAILED = 'oauth_login_failed';

    /**
     * @param string $acceptKey Initiating connection accept key the signal targets
     * @param string $provider Provider key the login was attempted against
     * @param string $reason Stable, non-sensitive failure reason code
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $provider,
        public readonly string $reason = self::REASON_LOGIN_FAILED,
    ) {
    }

    /**
     * @return string Accept key the failure signal is delivered to
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'provider' => $this->provider,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: (string)($data['acceptKey'] ?? ''),
            provider: (string)($data['provider'] ?? ''),
            reason: (string)($data['reason'] ?? self::REASON_LOGIN_FAILED),
        );
    }
}
