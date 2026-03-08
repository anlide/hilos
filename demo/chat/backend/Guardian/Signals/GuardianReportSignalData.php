<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Signals;

use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class GuardianReportSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly GuardianReportPayload $report,
    ) {
    }

    public function toArray(): array
    {
        return [
            'report' => $this->report->toArray(),
        ];
    }

    public static function fromArray(array $data): static
    {
        $rawReport = is_array($data['report'] ?? null) ? $data['report'] : [];
        return new self(
            report: GuardianReportPayload::fromArray($rawReport),
        );
    }
}
