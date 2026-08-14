<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\OAuthPendingLogin;

/**
 * OAuthPendingLoginSignalData - one verified pending login handed to the OAuth agent (HIL-281).
 *
 * The intake payload of mechanism B: the callback action verifies the signed state
 * synchronously (no I/O) and hands the resulting pending op to the monopolistic OAuth
 * agent over the {@see HilosSignalConstants::HILOS_OAUTH_PENDING}
 * agent signal. Because the callback runs on a worker page and the agent is a
 * leader-pinned singleton in another process, a synced point-to-point signal — not a
 * cross-process runtime collection — is what actually carries the op across that
 * boundary; the agent is the op's single consumer and owns its in-flight pool.
 *
 * It carries exactly the fields {@see OAuthPendingLogin::create()} needs, including the
 * absolute {@see deadlineMs} the callback computes, and the link-mode fields (HIL-401):
 * {@see mode} and {@see linkUserId}, which the agent uses to branch the success path.
 */
final class OAuthPendingLoginSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param string $acceptKey Initiating connection accept key (op id + failure/result target)
     * @param string $sessionToken Session token to authenticate on a login success
     * @param string $provider Provider key, e.g. 'oauth:github'
     * @param string $code Authorization code to exchange
     * @param float $deadlineMs Absolute deadline in milliseconds after which the exchange is abandoned
     * @param string $mode Flow mode ({@see OAuthPendingLogin::MODE_LOGIN} default, {@see OAuthPendingLogin::MODE_LINK})
     * @param int $linkUserId User the identity links to under link mode; 0 for a login exchange
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $sessionToken,
        public readonly string $provider,
        public readonly string $code,
        public readonly float $deadlineMs,
        public readonly string $mode = OAuthPendingLogin::MODE_LOGIN,
        public readonly int $linkUserId = 0,
    ) {
    }

    /**
     * Rebuilds the pending op this payload was minted from.
     *
     * @return OAuthPendingLogin Pending login op keyed by the initiating accept key
     */
    public function toPendingLogin(): OAuthPendingLogin
    {
        return OAuthPendingLogin::create(
            $this->acceptKey,
            $this->sessionToken,
            $this->provider,
            $this->code,
            $this->deadlineMs,
            $this->mode,
            $this->linkUserId,
        );
    }

    /**
     * @return array<string, float|int|string> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'sessionToken' => $this->sessionToken,
            'provider' => $this->provider,
            'code' => $this->code,
            'deadlineMs' => $this->deadlineMs,
            'mode' => $this->mode,
            'linkUserId' => $this->linkUserId,
        ];
    }

    /**
     * Rebuilds the payload the callback action minted.
     *
     * Every field is required, {@see mode} and {@see linkUserId} included: their
     * constructor defaults answer a call site that builds the op, while
     * {@see toArray()} always writes both keys, so a payload that lost one lost
     * it in transit. A missing {@see linkUserId} read as `0` would name a login
     * exchange, and the agent would sign the initiator in where it was asked to
     * link an identity to an account.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload is missing a field of the pending op
     */
    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: self::requireString($data, 'acceptKey'),
            sessionToken: self::requireString($data, 'sessionToken'),
            provider: self::requireString($data, 'provider'),
            code: self::requireString($data, 'code'),
            deadlineMs: self::requireFloat($data, 'deadlineMs'),
            mode: self::requireString($data, 'mode'),
            linkUserId: self::requireInt($data, 'linkUserId'),
        );
    }
}
