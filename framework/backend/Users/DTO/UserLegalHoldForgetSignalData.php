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
 * Test-only users library → the person's agent: forget acceptances later than a held revision (HIL-1899).
 *
 * Carried by {@see HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET} for {@see CliCommands::LEGAL_TEST_HOLD}.
 * A command runs outside a connection, so this is not a {@see HandoverAskInterface} ask.
 * {@see AbstractUsersLibraryAgent} records the held revision before this request.
 * {@see AbstractUserAgent} removes the later acceptances and returns the whole request in
 * {@see UserLegalHoldForgetDoneSignalData}; the library then checks the result and answers the command.
 */
final class UserLegalHoldForgetSignalData extends BaseDTO implements SignalDataInterface
{
    public const string userId = 'userId';
    public const string document = 'document';
    public const string revisionId = 'revisionId';
    public const string replySignal = 'replySignal';
    public const string correlationId = 'correlationId';

    /**
     * @param int $userId Person whose acceptances are removed, and the agent index
     * @param string $document Document key
     * @param string $revisionId Exact revision to hold
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $correlationId Correlation id of the parked command to answer
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $document,
        public readonly string $revisionId,
        public readonly string $replySignal,
        public readonly string $correlationId,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Test-only acceptance removal request
     * @throws InvalidFormatException When the person or the parked command is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            document: self::requireString($data, self::document),
            revisionId: self::requireString($data, self::revisionId),
            replySignal: self::requireString($data, self::replySignal),
            correlationId: self::requireString($data, self::correlationId),
        );
    }

    /** @return array<string, int|string> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::document => $this->document,
            self::revisionId => $this->revisionId,
            self::replySignal => $this->replySignal,
            self::correlationId => $this->correlationId,
        ];
    }
}
