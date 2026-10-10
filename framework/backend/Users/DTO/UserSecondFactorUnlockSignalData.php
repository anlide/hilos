<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the person's agent: lift the app-code lock an operator's command asked to lift (HIL-1406).
 *
 * What {@see HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK} carries, for
 * {@see CliCommands::SECOND_FACTOR_UNLOCK}. Not an ask of the handover form
 * ({@see HandoverAskInterface}): a command writes outside any connection. {@see AbstractUserAgent}
 * lifts the lock with its step, the miss count and its window, and sends this request back inside
 * {@see UserSecondFactorUnlockDoneSignalData}; {@see AbstractUsersLibraryAgent} answers the parked
 * command from it.
 */
final class UserSecondFactorUnlockSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string replySignal = 'replySignal';
    public const string correlationId = 'correlationId';

    /**
     * @param int $userId Person whose lock is lifted, and the index of the agent the request is for
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $correlationId Correlation id of the parked command to answer
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $replySignal,
        public readonly string $correlationId,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Lock lift request of a command
     * @throws InvalidFormatException When the person or the parked command is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            replySignal: self::requireString($data, self::replySignal),
            correlationId: self::requireString($data, self::correlationId),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::replySignal => $this->replySignal,
            self::correlationId => $this->correlationId,
        ];
    }
}
