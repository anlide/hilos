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
 * Notifications library → the person's agent: turn one notification channel on or off (HIL-1408).
 *
 * What {@see HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET} carries.
 * {@see AbstractNotificationsLibraryAgent} forwards the browser's toggle;
 * {@see AbstractUserAgent} writes the person's choice, tells the person's tabs the full channel
 * map, and sends this ask back inside {@see HandoverAnswerSignalData}.
 */
final class UserNotificationChannelSetSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string channel = 'channel';
    public const string enabled = 'enabled';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose channel is set, and the index of the agent the ask is for
     * @param string $channel Channel to turn on or off
     * @param bool $enabled Whether the channel stays on
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @throws InvalidFormatException When the person id is not positive
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $channel,
        public readonly bool $enabled,
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
     * @return static Channel-set ask
     * @throws InvalidFormatException When the person, the channel, the choice or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            channel: self::requireString($data, self::channel),
            enabled: self::requireBool($data, self::enabled),
            replySignal: self::requireString($data, self::replySignal),
            acceptKey: self::requireString($data, self::acceptKey),
            requestId: self::optionalString($data, self::requestId),
            action: self::requireString($data, self::action),
            successMessage: self::optionalString($data, self::successMessage),
        );
    }

    /** @return array<string, int|bool|string|null> Transport payload */
    public function toArray(): array
    {
        return [
            self::userId => $this->userId,
            self::channel => $this->channel,
            self::enabled => $this->enabled,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
