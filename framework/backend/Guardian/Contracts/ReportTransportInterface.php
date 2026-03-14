<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianReport;

/**
 * Transport interface for sending guardian reports to destination.
 *
 * Implementations may write to log, external service, or other sink.
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
