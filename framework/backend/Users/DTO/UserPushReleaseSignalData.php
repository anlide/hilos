<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\HandoverAskInterface;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Push\PushSubscriptionAction;

/**
 * Notifications library → the prior personal owner: release the push endpoint another person subscribes (HIL-1906).
 *
 * What {@see HilosSignalConstants::HILOS_USER_PUSH_RELEASE} carries. The owner removes its own row only
 * while that row still has this id, this owner and this endpoint, and returns the ask whole inside
 * {@see UserPushReleaseDoneSignalData}. The rest is the waiting press of the new subscriber - their id,
 * the keys and device of the browser, and the handover fields - so the library resumes that same
 * press from the answer alone. From the library the reply is
 * {@see HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE}, the action is
 * {@see PushSubscriptionAction::SUBSCRIBE} and no success sentence is set.
 */
final class UserPushReleaseSignalData extends BaseDTO implements HandoverAskInterface
{
    public const string userId = 'userId';
    public const string subscriptionId = 'subscriptionId';
    public const string endpoint = 'endpoint';
    public const string subscriberUserId = 'subscriberUserId';
    public const string p256dh = 'p256dh';
    public const string auth = 'auth';
    public const string userAgent = 'userAgent';
    public const string replySignal = 'replySignal';
    public const string acceptKey = 'acceptKey';
    public const string requestId = 'requestId';
    public const string action = 'action';
    public const string successMessage = 'successMessage';

    /**
     * @param int $userId Prior owner of the endpoint, and the index of the agent the ask is for
     * @param int $subscriptionId Prior owner's subscription row to release
     * @param string $endpoint Endpoint URL that row is expected to hold
     * @param int $subscriberUserId Person whose subscribe waits for the endpoint
     * @param string $p256dh Client public key of the waiting subscribe
     * @param string $auth Client auth secret of the waiting subscribe
     * @param ?string $userAgent Device user agent of the waiting subscribe, or null
     * @param string $replySignal Agent signal the owner answers the library under
     * @param string $acceptKey Accept key of the connection that asked, and the origin of the write
     * @param ?string $requestId Client-minted request id of the tracked submit, or null when untracked
     * @param string $action Browser action name the ack is addressed to
     * @param ?string $successMessage Sentence to speak on success, or null where the gesture has none
     * @throws InvalidFormatException When any id is not positive or an endpoint or key is empty
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $subscriptionId,
        public readonly string $endpoint,
        public readonly int $subscriberUserId,
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
        if ($subscriberUserId <= 0) {
            throw new InvalidFormatException('Subscriber user id must be positive');
        }
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            throw new InvalidFormatException('Push subscription endpoint and keys are required');
        }
    }

    /**
     * @param array<string, mixed> $data Transport payload
     * @return static Push-release ask
     * @throws InvalidFormatException When a person, the device or the waiting submit is invalid
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, self::userId),
            subscriptionId: self::requireInt($data, self::subscriptionId),
            endpoint: self::requireString($data, self::endpoint),
            subscriberUserId: self::requireInt($data, self::subscriberUserId),
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
            self::subscriberUserId => $this->subscriberUserId,
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
