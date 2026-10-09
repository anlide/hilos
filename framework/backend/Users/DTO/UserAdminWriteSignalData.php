<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Sessions library → the person's agent: write the admin flag the card asked for (HIL-1404).
 *
 * What {@see HilosSignalConstants::HILOS_USER_ADMIN_WRITE} carries. {@see AbstractSessionsLibraryAgent}
 * judged the request; {@see AbstractUserAgent} writes the flag and sends this ask back inside
 * {@see UserAdminWriteDoneSignalData}. The handover fields are the card's, echoed from
 * {@see AccountAdminSetSignalData}, and the last field is the name the card waits for its answer
 * under - the card's reply name, which the library answers on once the tabs are told.
 */
final class UserAdminWriteSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string admin = 'admin';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';
    public const string answerSignal = 'answerSignal';

    /**
     * @param int $userId Person whose flag is written, and the index of the agent the ask is for
     * @param bool $admin Flag to write
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null until the library composes it
     * @param string $answerSignal Name the library answers the card under
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $admin,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
        public readonly string $answerSignal,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Admin flag ask
     * @throws InvalidFormatException When the person, the flag or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            admin: self::requireBool($data, self::admin),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
            answerSignal: self::requireString($data, self::answerSignal),
        );
    }

    /** @return array<string, int|bool|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::admin => $this->admin,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
            self::answerSignal => $this->answerSignal,
        ];
    }
}
