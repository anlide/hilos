<?php

declare(strict_types=1);

namespace Hilos\Guardian\Actions;

use Hilos\Guardian\Contracts\ActionExecutorInterface;
use Hilos\Guardian\Contracts\ReportTransportInterface;
use Hilos\Guardian\DTO\GuardianActionRequest;
use Hilos\Guardian\DTO\GuardianReport;

final class CreateReportAction implements ActionExecutorInterface
{
    public function __construct(
        private readonly ReportTransportInterface $transport,
    ) {
    }

    public function execute(GuardianActionRequest $request): bool
    {
        $raw = $request->payload['report'] ?? null;
        if (!is_array($raw)) {
            return false;
        }

        $this->transport->send(GuardianReport::fromArray($raw));
        return true;
    }
}
