<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Any agent → the person's agent: apply the stored trust restrictions to the person's trusts (HIL-1900).
 *
 * Carried by {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT}. The frame is sent
 * because of one restriction, and {@see AbstractUserAgent} applies every restriction saved since the
 * person's earliest grant, then returns the whole request in
 * {@see UserSecondFactorTrustRestrictDoneSignalData} under the reply name it carries.
 */
final class UserSecondFactorTrustRestrictSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string restrictionId = 'restrictionId';
    public const string replySignal = 'replySignal';

    /**
     * @param int $userId Person whose trusts are restricted, and the agent index
     * @param int $restrictionId Stored restriction the request is made for
     * @param string $replySignal Agent signal the agent answers the sender under
     * @throws InvalidFormatException When the person id or the restriction id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $restrictionId,
        public readonly string $replySignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if ($restrictionId <= 0) {
            throw new InvalidFormatException('Trust restriction id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Trust restriction request
     * @throws InvalidFormatException When the person, the restriction or the reply name is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            restrictionId: self::requireInt($data, self::restrictionId),
            replySignal: self::requireString($data, self::replySignal),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::restrictionId => $this->restrictionId,
            self::replySignal => $this->replySignal,
        ];
    }
}
