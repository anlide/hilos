<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;
use Hilos\Guardian\Enums\FindingType;
use Hilos\Guardian\Enums\Severity;

final class GuardianFinding extends BaseDTO
{
    /**
     * Creates guardian finding.
     *
     * @param FindingType $type Finding type
     * @param Severity $severity Severity level
     * @param string $title Finding title
     * @param string $message Finding message
     * @param array<string, mixed> $details Optional extra data
     */
    public function __construct(
        public readonly FindingType $type,
        public readonly Severity $severity,
        public readonly string $title,
        public readonly string $message,
        public readonly array $details = [],
    ) {
    }

    /**
     * Converts to array for serialization.
     *
     * @return array<string, mixed> Finding data with type, severity, title, message, details
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }

    /**
     * Creates finding from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static Finding instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            type: FindingType::from((string) ($data['type'] ?? FindingType::BUSINESS->value)),
            severity: Severity::from((string) ($data['severity'] ?? Severity::INFO->value)),
            title: (string) ($data['title'] ?? ''),
            message: (string) ($data['message'] ?? ''),
            details: is_array($data['details'] ?? null) ? $data['details'] : [],
        );
    }
}
