<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * PageSubscriptionErrorSignalData - Error signal data for page subscription failures.
 *
 * Sent when AbstractPage::onSubscribe throws PageSubscriptionException.
 * The subscription remains active, but the client receives structured error info.
 */
class PageSubscriptionErrorSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates page subscription error signal data.
     *
     * @param string $page Page name that failed subscription
     * @param int $httpCode HTTP status code (404, 403, 500, etc.)
     * @param string $errorCode Machine-readable error code ('not_found', 'forbidden', etc.)
     * @param string $message Human-readable error message
     */
    public function __construct(
        public readonly string $page,
        public readonly int $httpCode,
        public readonly string $errorCode,
        public readonly string $message,
    ) {
    }

    /**
     * Converts error data to array for transport.
     *
     * @return array<string, mixed> Error data as array
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'httpCode' => $this->httpCode,
            'errorCode' => $this->errorCode,
            'message' => $this->message,
        ];
    }

    /**
     * Creates error data from array.
     *
     * Every field is what the client shows the user, and none of the four has a
     * value that could stand in for a missing one: a subscription failure that
     * arrived without its status or its code is a broken frame, and inventing a
     * generic 500 for it would hide the break behind a plausible error page.
     *
     * @param array<string, mixed> $data Source data
     * @return static Error data instance
     * @throws InvalidFormatException When any of the four fields is missing or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: self::requireString($data, 'page'),
            httpCode: self::requireInt($data, 'httpCode'),
            errorCode: self::requireString($data, 'errorCode'),
            message: self::requireString($data, 'message'),
        );
    }
}
