<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Transport;

use Demo\Chat\Guardian\Reports\GuardianReportPayload;

/**
 * ReportTransportInterface - Interface for sending guardian reports to a sink.
 */
interface ReportTransportInterface
{
    /**
     * Send report to the configured sink (log, external service, etc.).
     *
     * @param GuardianReportPayload $report Report payload
     */
    public function send(GuardianReportPayload $report): void;
}
