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
    /** Wire key for the optional ready deletion line. Absent when the refusal has none. */
    public const string transitionMessage = 'transitionMessage';

    /**
     * Creates page subscription error signal data.
     *
     * @param string $page Page name that failed subscription
     * @param int $httpCode HTTP status code (404, 403, 500, etc.)
     * @param string $errorCode Machine-readable error code ('not_found', 'forbidden', etc.)
     * @param string $title Public refusal title
     * @param string $message Public refusal description
     * @param ?string $transitionMessage Ready deletion line, or null when the refusal has none
     */
    public function __construct(
        public readonly string $page,
        public readonly int $httpCode,
        public readonly string $errorCode,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $transitionMessage = null,
    ) {
    }

    /**
     * Builds the public refusal without accepting an exception's diagnostic text.
     *
     * A 404 does not grow a deletion line on its own. The line is present only
     * when the caller passes one.
     *
     * @param string $page Page name that failed subscription
     * @param int $httpCode HTTP status of the refusal
     * @param string $errorCode Machine-readable refusal code
     * @param ?string $transitionMessage Ready deletion line, or null when the refusal has none
     * @return static Refusal with catalog wording
     */
    public static function forStatus(
        string $page,
        int $httpCode,
        string $errorCode,
        ?string $transitionMessage = null,
    ): static {
        $text = PageErrorMessages::forStatus($httpCode);

        return new static($page, $httpCode, $errorCode, $text->title, $text->message, $transitionMessage);
    }

    /**
     * Converts error data to array for transport.
     *
     * The deletion line is omitted entirely when it is null, so an ordinary
     * refusal carries no key for it.
     *
     * @return array<string, mixed> Error data as array
     */
    public function toArray(): array
    {
        $payload = [
            'page' => $this->page,
            'httpCode' => $this->httpCode,
            'errorCode' => $this->errorCode,
            'title' => $this->title,
            'message' => $this->message,
        ];
        if ($this->transitionMessage !== null) {
            $payload[self::transitionMessage] = $this->transitionMessage;
        }

        return $payload;
    }

    /**
     * Creates error data from array.
     *
     * Status, code and public wording are required; malformed payloads are refused.
     * The deletion line may be absent. A present null, non-string or empty value is refused.
     *
     * @param array<string, mixed> $data Source data
     * @return static Error data instance
     * @throws InvalidFormatException When a field is missing, mistyped, or empty, or the deletion line is present but not text
     */
    public static function fromArray(array $data): static
    {
        $title = self::requireString($data, 'title');
        $message = self::requireString($data, 'message');
        if ($title === '' || $message === '') {
            throw new InvalidFormatException('Page subscription error title and message must be non-empty');
        }

        $transitionMessage = null;
        if (array_key_exists(self::transitionMessage, $data)) {
            $transitionMessage = $data[self::transitionMessage];
            if (!is_string($transitionMessage) || $transitionMessage === '') {
                throw new InvalidFormatException(
                    'Page subscription error transition message must be a non-empty string',
                );
            }
        }

        return new static(
            page: self::requireString($data, 'page'),
            httpCode: self::requireInt($data, 'httpCode'),
            errorCode: self::requireString($data, 'errorCode'),
            title: $title,
            message: $message,
            transitionMessage: $transitionMessage,
        );
    }
}
