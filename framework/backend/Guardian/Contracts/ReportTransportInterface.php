<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianReport;

interface ReportTransportInterface
{
    public function send(GuardianReport $report): void;
}
