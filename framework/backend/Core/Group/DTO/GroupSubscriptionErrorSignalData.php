<?php

declare(strict_types=1);

namespace Hilos\Core\Group\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\Exception\GroupSubscriptionException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * GroupSubscriptionErrorSignalData - the frame a refused group join is answered with.
 *
 * Sent in place of {@see GroupResponseSignalData} whenever a join does not happen: no
 * registered class answers the name, the group's own admission said no, the name was
 * addressed the wrong way, or the server does not know who is asking. The group name echoed
 * back is the one the CLIENT sent, because that is the name it is waiting on - a refused
 * join never reached the full name the server would have built.
 */
final class GroupSubscriptionErrorSignalData extends BaseDTO implements SignalDataInterface
{
    public const string group = 'group';
    public const string httpCode = 'httpCode';
    public const string errorCode = 'errorCode';
    public const string message = 'message';

    /**
     * Creates group subscription error signal data.
     *
     * @param string $group Group name the client asked to join
     * @param int $httpCode HTTP status code (404, 403, 400, 401, 500)
     * @param string $errorCode Machine-readable refusal code {@see GroupSubscriptionException::$errorCode}
     * @param string $message Human-readable refusal message
     */
    public function __construct(
        public readonly string $group,
        public readonly int $httpCode,
        public readonly string $errorCode,
        public readonly string $message,
    ) {
    }

    /**
     * Converts the refusal to its wire array.
     *
     * @return array<string, mixed> DTO payload in the `{group, httpCode, errorCode, message}` wire form
     */
    public function toArray(): array
    {
        return [
            self::group => $this->group,
            self::httpCode => $this->httpCode,
            self::errorCode => $this->errorCode,
            self::message => $this->message,
        ];
    }

    /**
     * Restores the refusal from its wire array.
     *
     * All four fields are required for the reason its page twin requires its own: none of
     * them has a value that could stand in for a missing one, and a generic code invented
     * here would hide a broken frame behind a plausible refusal.
     *
     * @param array<string, mixed> $data Source data
     * @return static Restored DTO instance
     * @throws InvalidFormatException When any of the four fields is missing or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            group: self::requireString($data, self::group),
            httpCode: self::requireInt($data, self::httpCode),
            errorCode: self::requireString($data, self::errorCode),
            message: self::requireString($data, self::message),
        );
    }
}
