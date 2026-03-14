<?php

declare(strict_types=1);

namespace Hilos\Guardian\DTO;

use Hilos\BaseDTO;

/**
 * Guardian report DTO with findings and metadata.
 */
final class GuardianReport extends BaseDTO
{
    /**
     * Creates guardian report.
     *
     * @param string $guardianType Guardian type identifier
     * @param string $summary Report summary
     * @param list<GuardianFinding> $findings Finding list (default empty)
     * @param array<string, mixed> $meta Additional metadata (default empty)
     */
    public function __construct(
        public readonly string $guardianType,
        public readonly string $summary,
        public readonly array $findings = [],
        public readonly array $meta = [],
    ) {
    }

    /**
     * Converts report to array for serialization.
     *
     * @return array<string, mixed> Report as associative array
     */
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

    /**
     * Creates report from array (e.g. from DB or JSON).
     *
     * @param array<string, mixed> $data Input data (guardianType, summary, findings, meta)
     * @return static New GuardianReport instance
     */
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
