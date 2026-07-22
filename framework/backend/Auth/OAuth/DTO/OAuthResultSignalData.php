<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * OAuthResultSignalData - the OAuth callback out-of-band result signal (HIL-281 / HIL-282).
 *
 * Delivered WS_USER to the initiating connection's accept key when the async
 * outcome cannot ride the login handshake. A plain success needs no signal of its
 * own — it rides HIL-161's existing session/currentUser fan-out — so the SPA
 * callback surface, which shows a spinner after `oauthCallback`, resolves on
 * EITHER a currentUser update (login) OR this signal, branching on {@see reason}:
 *
 * - {@see REASON_LOGIN_FAILED} — the exchange failed, timed out, or resolved no
 *   subject: a generic failure (HIL-281). {@see email} / {@see linkToken} are empty.
 * - {@see REASON_REAUTH_REQUIRED} — the provider email collided with an existing
 *   verified identity (HIL-282): the surface must re-authenticate the owner (with
 *   {@see email} pre-filled) and then redeem {@see linkToken} through the link
 *   action. No user was created and nobody was signed in.
 * - {@see REASON_LINK_OK} / {@see REASON_LINK_DUPLICATE} / {@see REASON_LINK_FAILED} —
 *   the terminal outcomes of a profile link-mode exchange (HIL-401), where the
 *   initiator is already signed in and the session is never touched, so every
 *   outcome (including success) must be signalled explicitly. {@see email} /
 *   {@see linkToken} are empty.
 *
 * The {@see reason} is a stable, non-sensitive code; network/provider failure
 * detail stays in the agent log, never on the wire. {@see linkToken} is a signed,
 * self-contained capability (not a secret about the matched account): it discloses
 * only that *some* account owns {@see email}, which the collision already implies.
 */
final class OAuthResultSignalData extends BaseDTO implements SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /** Generic failure reason surfaced to the client (provider/network detail is logged, not sent). */
    public const string REASON_LOGIN_FAILED = 'oauth_login_failed';

    /** Re-auth-to-link reason: the provider email collided with an existing verified identity (HIL-282). */
    public const string REASON_REAUTH_REQUIRED = 'reauth_required';

    /** Profile link succeeded: the provider identity was bound to the initiating account (HIL-401). */
    public const string REASON_LINK_OK = 'oauth_link_ok';

    /** Profile link refused: the provider identity is already linked to some account (HIL-401). */
    public const string REASON_LINK_DUPLICATE = 'oauth_link_duplicate';

    /** Profile link failed: the exchange or the identity write did not complete (HIL-401). */
    public const string REASON_LINK_FAILED = 'oauth_link_failed';

    /**
     * @param string $acceptKey Initiating connection accept key the signal targets
     * @param string $provider Provider key the login was attempted against
     * @param string $reason Stable, non-sensitive result reason code
     * @param string $email Colliding email to pre-fill for re-auth ('' for the failure arm)
     * @param string $linkToken Signed link-capability token to redeem after re-auth ('' for the failure arm)
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $provider,
        public readonly string $reason = self::REASON_LOGIN_FAILED,
        public readonly string $email = '',
        public readonly string $linkToken = '',
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
            'email' => $this->email,
            'linkToken' => $this->linkToken,
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
            email: (string)($data['email'] ?? ''),
            linkToken: (string)($data['linkToken'] ?? ''),
        );
    }
}
