<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Reports;

use Hilos\BaseDTO;

final class GuardianReportPayload extends BaseDTO
{
    /**
     * @param array<string, mixed> $details
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
