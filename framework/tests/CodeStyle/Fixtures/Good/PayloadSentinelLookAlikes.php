<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample: none of these is a minted stub, and the rule has to stay
 * silent on every one of them. Absence spelled as null or as an empty section,
 * a literal written down as data rather than fallen back to, a `switch` label
 * that only looks like a `match` arm, the same fallback outside a payload
 * reader, and one occurrence named in place by its marker.
 */
final class PayloadSentinelLookAlikes
{
    /**
     * @param ?string $page Page the frame names, absent when the frame names none
     * @param array<string, mixed> $payload Sections the frame carries
     * @param string $title Title as the database driver returned it
     */
    private function __construct(
        private readonly ?string $page,
        private readonly array $payload,
        private readonly string $title,
    ) {
    }

    /**
     * @param array<string, mixed> $data Payload to read
     * @return self Sample built from the payload
     */
    public static function fromArray(array $data): self
    {
        $page = $data['page'] ?? null;
        $payload = $data['payload'] ?? [];
        $labels = ['none' => '', 'kind' => 'page'];

        // A fallback of `?? ''` written in a comment is text, and '?? 0' quoted
        // inside a string is text too.
        // external-boundary: the title column is NOT NULL, so the driver always hands its stored value over
        return new self($page, $payload + $labels, (string)($data['title'] ?? ''));
    }

    /**
     * @param array<string, mixed> $data Payload to read
     * @return int Status the frame carries, defaulted where this object owns the default
     */
    public static function statusOf(array $data): int
    {
        $status = $data['httpCode'] ?? 0;

        return is_int($status) ? $status : 0;
    }

    /**
     * @param string $kind Kind to classify
     * @return string Group the kind belongs to
     */
    public function group(string $kind): string
    {
        switch ($kind) {
            case 'page':
                return 'pages';
            default:
                return '';
        }
    }
}
