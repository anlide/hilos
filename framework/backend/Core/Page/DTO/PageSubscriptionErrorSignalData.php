<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageErrorMessages;
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
     * @param string $title Public refusal title
     * @param string $message Public refusal description
     */
    public function __construct(
        public readonly string $page,
        public readonly int $httpCode,
        public readonly string $errorCode,
        public readonly string $title,
        public readonly string $message,
    ) {
    }

    /**
     * Builds the public refusal without accepting an exception's diagnostic text.
     *
     * @param string $page Page name that failed subscription
     * @param int $httpCode HTTP status of the refusal
     * @param string $errorCode Machine-readable refusal code
     * @return static Refusal with catalog wording
     */
    public static function forStatus(string $page, int $httpCode, string $errorCode): static
    {
        $text = PageErrorMessages::forStatus($httpCode);

        return new static($page, $httpCode, $errorCode, $text->title, $text->message);
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
            'title' => $this->title,
            'message' => $this->message,
        ];
    }

    /**
     * Creates error data from array.
     *
     * Status, code and public wording are required; malformed payloads are refused.
     *
     * @param array<string, mixed> $data Source data
     * @return static Error data instance
     * @throws InvalidFormatException When a field is missing, mistyped, or public wording is empty
     */
    public static function fromArray(array $data): static
    {
        $title = self::requireString($data, 'title');
        $message = self::requireString($data, 'message');
        if ($title === '' || $message === '') {
            throw new InvalidFormatException('Page subscription error title and message must be non-empty');
        }

        return new static(
            page: self::requireString($data, 'page'),
            httpCode: self::requireInt($data, 'httpCode'),
            errorCode: self::requireString($data, 'errorCode'),
            title: $title,
            message: $message,
        );
    }
}
