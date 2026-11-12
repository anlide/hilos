<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Sessions library → the person's agent: extend the standing trust of a browser to the moment it was confirmed (HIL-1907).
 *
 * What {@see HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW} carries.
 * {@see AbstractSessionsLibraryAgent} found the pair of the browser and the person standing when the
 * second factor was confirmed with "trust this browser"; {@see AbstractUserAgent} moves the pair's end
 * and its last grant to what this request names, and sends it back inside
 * {@see UserBrowserTrustRenewDoneSignalData}.
 */
final class UserBrowserTrustRenewSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';
    public const string trustedUntil = 'trustedUntil';
    public const string grantedAt = 'grantedAt';
    public const string replySignal = 'replySignal';

    /**
     * @param int $userId Person the browser is trusted for, and the index of the agent the request is for
     * @param int $sessionId Session row of the browser whose trust is extended
     * @param int $trustedUntil Moment the extended trust runs out (epoch milliseconds)
     * @param int $grantedAt Moment the second factor was confirmed with the trust asked for (epoch milliseconds)
     * @param string $replySignal Agent signal the agent answers the holder under
     * @throws InvalidFormatException When the person, the browser or the grant is not positive, or the trust ends no later than it is granted
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
        public readonly int $trustedUntil,
        public readonly int $grantedAt,
        public readonly string $replySignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if ($sessionId <= 0) {
            throw new InvalidFormatException('Session id must be positive');
        }
        if ($grantedAt <= 0) {
            throw new InvalidFormatException('Grant moment must be positive');
        }
        if ($trustedUntil <= $grantedAt) {
            throw new InvalidFormatException('Trust must end after it is granted');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Trust renewal request
     * @throws InvalidFormatException When the person, the browser, a moment or the reply name is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            sessionId: self::requireInt($data, self::sessionId),
            trustedUntil: self::requireInt($data, self::trustedUntil),
            grantedAt: self::requireInt($data, self::grantedAt),
            replySignal: self::requireString($data, self::replySignal),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
            self::trustedUntil => $this->trustedUntil,
            self::grantedAt => $this->grantedAt,
            self::replySignal => $this->replySignal,
        ];
    }
}
