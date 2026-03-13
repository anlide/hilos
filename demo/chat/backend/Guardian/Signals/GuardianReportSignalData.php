<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Signals;

use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class GuardianReportSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param GuardianReportPayload $report Guardian report payload
     */
    public function __construct(
        public readonly GuardianReportPayload $report,
    ) {
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> Report as associative array
     */
    public function toArray(): array
    {
        return [
            'report' => $this->report->toArray(),
        ];
    }

    /**
     * Create DTO from array (for deserialization).
     *
     * @param array<string, mixed> $data Source data with report key
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $rawReport = is_array($data['report'] ?? null) ? $data['report'] : [];
        return new self(
            report: GuardianReportPayload::fromArray($rawReport),
        );
    }
}
