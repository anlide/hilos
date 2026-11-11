<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Notifications library → the personal owner: renew the captured push subscription (HIL-1905).
 *
 * The endpoint is an attribute of that id, never a fallback address for a replacement row.
 * The owner returns this ask inside {@see HandoverAnswerSignalData} after the persisted write.
 */
final class UserPushSubscribeSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string subscriptionId = 'subscriptionId';
    public const string endpoint = 'endpoint';
    public const string p256dh = 'p256dh';
    public const string auth = 'auth';
    public const string userAgent = 'userAgent';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Person whose device is renewed, and the index of the agent the ask is for
     * @param int $subscriptionId Captured subscription row to renew
     * @param string $endpoint Captured endpoint URL
     * @param string $p256dh Client public key
     * @param string $auth Client auth secret
     * @param ?string $userAgent Device user agent, or null
     * @param string $replySignal Agent signal the agent answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @throws InvalidFormatException When either id is not positive or an endpoint or key is empty
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $subscriptionId,
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
        public readonly ?string $userAgent,
        public readonly string $replySignal,
        public readonly string $acceptKey,
        public readonly ?string $requestId,
        public readonly string $action,
        public readonly ?string $successMessage,
    ) {
        if ($userId <= 0) {
            throw new InvalidFormatException('User id must be positive');
        }
        if ($subscriptionId <= 0) {
            throw new InvalidFormatException('Subscription id must be positive');
        }
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new InvalidFormatException('Push subscription endpoint and keys are required');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Push-renewal ask
     * @throws InvalidFormatException When the person, the device or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            subscriptionId: self::requireInt($data, self::subscriptionId),
            endpoint: self::requireString($data, self::endpoint),
            p256dh: self::requireString($data, self::p256dh),
            auth: self::requireString($data, self::auth),
            userAgent: self::optionalString($data, self::userAgent),
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
            self::subscriptionId => $this->subscriptionId,
            self::endpoint => $this->endpoint,
            self::p256dh => $this->p256dh,
            self::auth => $this->auth,
            self::userAgent => $this->userAgent,
            self::replySignal => $this->replySignal,
            self::acceptKey => $this->acceptKey,
            self::requestId => $this->requestId,
            self::action => $this->action,
            self::successMessage => $this->successMessage,
        ];
    }
}
