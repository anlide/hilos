<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Reports;

use Hilos\BaseDTO;

/**
 * GuardianReportPayload - DTO for guardian report content.
 *
 * Contains guardian identifier, category, severity, title, message and optional details.
 */
final class GuardianReportPayload extends BaseDTO
{
    /**
     * Creates guardian report payload.
     *
     * @param string $guardian Guardian identifier
     * @param string $category Report category
     * @param string $severity Severity (e.g. info, warning, error)
     * @param string $title Report title
     * @param string $message Report message
     * @param array<string, mixed> $details Optional extra data
     */
    public function __construct(
        public readonly string $guardian,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $message,
        public readonly array $details = [],
    ) {
    }

    /**
     * Convert payload to array for serialization.
     *
     * @return array<string, mixed> Payload data
     */
    public function toArray(): array
    {
        return [
            'guardian' => $this->guardian,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }

    /**
     * Create payload from array (deserialization).
     *
     * @param array<string, mixed> $data Source data
     * @return static Instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            guardian: (string) ($data['guardian'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            severity: (string) ($data['severity'] ?? 'info'),
            title: (string) ($data['title'] ?? ''),
            message: (string) ($data['message'] ?? ''),
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
        );
    }
}
