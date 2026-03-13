<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Demo\Chat\Guardian\Signals\GuardianReportSignalData;
use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;

/**
 * ChatAgentGuardianSignalCapability - Sends guardian report to ChatAgent via GUARDIAN_REPORT signal.
 *
 * Requires sendSignal callback in context to forward the signal.
 */
final class ChatAgentGuardianSignalCapability extends AbstractGuardianCapability
{
    /**
     * Get capability name.
     *
     * @return string Capability identifier
     */
    public function getName(): string
    {
        return 'chat_agent.guardian_signal.send';
    }

    /**
     * Execute capability: send guardian report to ChatAgent.
     *
     * @param array $payload Payload with report array
     * @param array $context Context with sendSignal callable
     * @return CapabilityResult Ok with signal name, or error if payload/context invalid
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult
    {
        $reportRaw = $payload['report'] ?? null;
        if (!is_array($reportRaw)) {
            return new CapabilityResult(false, error: 'report must be an array');
        }

        $sendSignal = $context['sendSignal'] ?? null;
        if (!is_callable($sendSignal)) {
            return new CapabilityResult(false, error: 'sendSignal callback not provided');
        }

        $report = GuardianReportPayload::fromArray($reportRaw);
        $signalData = new GuardianReportSignalData($report);
        $sendSignal(ChatSignalConstants::GUARDIAN_REPORT, $signalData);

        return new CapabilityResult(true, ['signal' => ChatSignalConstants::GUARDIAN_REPORT]);
    }
}
