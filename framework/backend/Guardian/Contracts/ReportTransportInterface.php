<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianReport;

/**
 * Transport interface for sending guardian reports to destination.
 */
interface ReportTransportInterface
{
    /**
     * Send guardian report to transport destination.
     *
     * @param GuardianReport $report Report to send
     */
    public function send(GuardianReport $report): void;
}
