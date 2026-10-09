<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Users library → the person's agent: give this person that name (HIL-1404).
 *
 * What {@see HilosSignalConstants::HILOS_USER_RENAME} carries. The library judged the rename and
 * keeps what follows it; {@see AbstractUserAgent} writes the name and its journal row and sends
 * this ask back inside {@see UserRenameDoneSignalData}, untouched, so the library continues from
 * the frame alone.
 *
 * The handover fields name whoever pressed the button, so the write is stamped with them. The last
 * field is the name the library answers the page under: the card's own reply name, or null for a
 * rename the person asked for themselves, which is answered straight to their connection
 * ({@see AbstractUsersLibraryAgent}).
 */
final class UserRenameSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string name = 'name';
    public const string renamedByUserId = 'renamedByUserId';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';
    public const string answerSignal = 'answerSignal';

    /**
     * @param int $userId Person to rename, and the index of the agent the ask is for
     * @param string $name Name to give
     * @param ?int $renamedByUserId Person who did the rename - the renamed person's own id when they renamed
     *     themselves - or null when the author is not a person
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the surface has none
     * @param ?string $answerSignal Name the library answers the page under, or null to answer the connection itself
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly ?int $renamedByUserId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly ?string $answerSignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Rename ask
     * @throws InvalidFormatException When the person, the name or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            name: self::requireString($data, self::name),
            renamedByUserId: self::optionalInt($data, self::renamedByUserId),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
            answerSignal: self::optionalString($data, self::answerSignal),
        );
    }

    /** @return array<string, int|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::name => $this->name,
            self::renamedByUserId => $this->renamedByUserId,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
            self::answerSignal => $this->answerSignal,
        ];
    }
}
