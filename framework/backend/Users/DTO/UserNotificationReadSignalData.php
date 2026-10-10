<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Notifications library → the person's agent: mark one notification read, or every one (HIL-1408).
 *
 * What {@see HilosSignalConstants::HILOS_USER_NOTIFICATION_READ} carries.
 * {@see AbstractNotificationsLibraryAgent} forwards the browser's mark;
 * {@see AbstractUserAgent} marks the row, or every unread row of the person, tells the person's
 * tabs, and sends this ask back inside {@see HandoverAnswerSignalData}.
 */
final class UserNotificationReadSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string notificationId = 'notificationId';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose notification is marked, and the index of the agent the ask is for
     * @param ?int $notificationId Notification to mark, or null for every one of the person's
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $notificationId,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Mark-read ask
     * @throws InvalidFormatException When the person, the notification or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            notificationId: self::optionalInt($data, self::notificationId),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, int|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::notificationId => $this->notificationId,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
