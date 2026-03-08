<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;

final class GuardianReport extends BaseDTO
{
    /**
     * @param list<GuardianFinding> $findings
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $guardianType,
        public readonly string $summary,
        public readonly array $findings = [],
        public readonly array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'guardianType' => $this->guardianType,
            'summary' => $this->summary,
            'findings' => array_map(
                static fn (GuardianFinding $finding): array => $finding->toArray(),
                $this->findings
            ),
            'meta' => $this->meta,
        ];
    }

    public static function fromArray(array $data): static
    {
        $rawFindings = is_array($data['findings'] ?? null) ? $data['findings'] : [];
        $findings = [];
        foreach ($rawFindings as $rawFinding) {
            if (!is_array($rawFinding)) {
                continue;
            }
            $findings[] = GuardianFinding::fromArray($rawFinding);
        }

        return new self(
            guardianType: (string) ($data['guardianType'] ?? ''),
            summary: (string) ($data['summary'] ?? ''),
            findings: $findings,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }
}
