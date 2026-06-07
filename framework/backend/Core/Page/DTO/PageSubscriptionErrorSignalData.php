<?php

declare(strict_types=1);

namespace Hilos\Core\Page\DTO;

use Hilos\BaseDTO;
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
     * @param array<string, mixed> $data Source data
     * @return static Error data instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            page: (string) ($data['page'] ?? ''),
            httpCode: (int) ($data['httpCode'] ?? 500),
            errorCode: (string) ($data['errorCode'] ?? 'error'),
            message: (string) ($data['message'] ?? 'Unknown error'),
        );
    }
}
