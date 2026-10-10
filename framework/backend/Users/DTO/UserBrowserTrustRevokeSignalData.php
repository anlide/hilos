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
 * Sessions library → the person's agent: browsers whose sessions were ended no longer skip the second factor (HIL-1407).
 *
 * What {@see HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE} carries.
 * {@see AbstractSessionsLibraryAgent} ended the sessions and answered the browser;
 * {@see AbstractUserAgent} takes away the trust - of the one browser it names, or of every browser
 * of the person but the one it names - and sends this request back inside
 * {@see UserBrowserTrustRevokeDoneSignalData}.
 */
final class UserBrowserTrustRevokeSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string sessionId = 'sessionId';
    public const string others = 'others';
    public const string replySignal = 'replySignal';

    /**
     * @param int $userId Person whose trust is taken away, and the index of the agent the request is for
     * @param int $sessionId Session row of the browser losing trust, or with others the one keeping it - 0 when none keeps it
     * @param bool $others Whether every browser of the person but the named one loses trust, rather than the named one
     * @param string $replySignal Agent signal the agent answers the holder under
     * @throws InvalidFormatException When the person id is not positive, or one browser is named by no session row
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
        public readonly bool $others,
        public readonly string $replySignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if (!$others && $sessionId <= 0) {
            throw new InvalidFormatException('Session id must be positive when one browser loses trust');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Trust revocation request
     * @throws InvalidFormatException When the person, the browser, the scope or the reply name is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            sessionId: self::requireInt($data, self::sessionId),
            others: self::requireBool($data, self::others),
            replySignal: self::requireString($data, self::replySignal),
        );
    }

    /** @return array<string, int|bool|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::sessionId => $this->sessionId,
            self::others => $this->others,
            self::replySignal => $this->replySignal,
        ];
    }
}
