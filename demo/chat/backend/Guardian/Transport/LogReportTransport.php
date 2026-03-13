<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Transport;

use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Hilos\Utils\Logger;

/**
 * LogReportTransport - Logs guardian reports via Logger (no external sink).
 */
final class LogReportTransport implements ReportTransportInterface
{
    /**
     * Send report to log output.
     *
     * @param GuardianReportPayload $report Report to log
     */
    public function send(GuardianReportPayload $report): void
    {
        Logger::logAgentInfo(
            'guardian_transport',
            '[report] ' . json_encode($report->toArray(), JSON_UNESCAPED_UNICODE)
        );
    }
}
