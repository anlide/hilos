<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Transport;

use Demo\Chat\Guardian\Reports\GuardianReportPayload;

interface ReportTransportInterface
{
    public function send(GuardianReportPayload $report): void;
}
