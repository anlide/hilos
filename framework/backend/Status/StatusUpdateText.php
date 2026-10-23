<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Status\Exception\StatusIncidentWriteException;

/** Normalized plain text for an immutable Status update; escaping belongs to its output boundary. */
final readonly class StatusUpdateText
{
    public const int MAX_TEXT_LENGTH = 5000;

    public string $value;

    /**
     * @param string $text Update text before trimming
     * @throws StatusIncidentWriteException When text is invalid UTF-8, empty, or too long
     */
    public function __construct(string $text)
    {
        $text = trim($text);
        if (!mb_check_encoding($text, 'UTF-8') || $text === '' || mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH) {
            throw new StatusIncidentWriteException('Enter an update of 1 to 5000 characters.');
        }
        $this->value = $text;
    }
}
