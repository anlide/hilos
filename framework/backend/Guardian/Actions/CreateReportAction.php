<?php

declare(strict_types=1);

namespace Hilos\Guardian\Actions;

use Hilos\Guardian\Contracts\ActionExecutorInterface;
use Hilos\Guardian\Contracts\ReportTransportInterface;
use Hilos\Guardian\DTO\GuardianActionRequest;
use Hilos\Guardian\DTO\GuardianReport;

/**
 * Action that sends guardian report via transport.
 *
 * Executes "create report" requests from guardian investigation flow.
 */
final class CreateReportAction implements ActionExecutorInterface
{
    /**
     * Creates action with report transport.
     *
     * @param ReportTransportInterface $transport Transport for sending reports
     */
    public function __construct(
        private readonly ReportTransportInterface $transport,
    ) {
    }

    /**
     * Executes create report action from guardian request.
     *
     * @param GuardianActionRequest $request Action request with report payload
     * @return bool True if report was sent successfully
     */
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
