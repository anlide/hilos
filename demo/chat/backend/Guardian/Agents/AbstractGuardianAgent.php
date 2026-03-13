<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Agents;

use Demo\Chat\Guardian\Capabilities\ChatAgentGuardianSignalCapability;
use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Demo\Chat\Guardian\Transport\LogReportTransport;
use Demo\Chat\Guardian\Transport\ReportTransportInterface;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Utils\Logger;

/**
 * AbstractGuardianAgent - Abstract base for guardian agents.
 *
 * Provides report publishing via transport and optional chat signal forwarding
 * for medium/high/critical severity.
 */
abstract class AbstractGuardianAgent extends AbstractAgent
{
    protected ReportTransportInterface $transport;
    private ChatAgentGuardianSignalCapability $chatSignalCapability;

    /**
     * Initialize transport and chat signal capability.
     */
    public function __construct()
    {
        $this->transport = new LogReportTransport();
        $this->chatSignalCapability = new ChatAgentGuardianSignalCapability();
    }

    /**
     * Publish report to transport and optionally forward to chat agent.
     *
     * @param GuardianReportPayload $report Report payload
     */
    protected function publishReport(GuardianReportPayload $report): void
    {
        $this->transport->send($report);

        if (!$this->shouldSendToChat($report)) {
            return;
        }

        $result = $this->chatSignalCapability->execute(
            payload: ['report' => $report->toArray()],
            context: [
                'sendSignal' => function (string $signalName, mixed $signalData): void {
                    $this->sendToAgent($signalName, $signalData);
                },
            ]
        );

        if (!$result->ok) {
            Logger::logAgentError($this->getId(), 'Failed to send guardian signal: ' . ($result->error ?? 'unknown'));
        }
    }

    /**
     * Check if report should be forwarded to chat agent (medium/high/critical).
     *
     * @param GuardianReportPayload $report Report payload
     * @return bool True if report should be sent to chat
     */
    private function shouldSendToChat(GuardianReportPayload $report): bool
    {
        return match (strtolower($report->severity)) {
            'medium', 'high', 'critical' => true,
            default => false,
        };
    }
}
