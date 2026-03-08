<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Capabilities;

use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Demo\Chat\Guardian\Signals\GuardianReportSignalData;
use Hilos\Guardian\Capabilities\AbstractGuardianCapability;
use Hilos\Guardian\DTO\CapabilityResult;

final class ChatAgentGuardianSignalCapability extends AbstractGuardianCapability
{
    public function getName(): string
    {
        return 'chat_agent.guardian_signal.send';
    }

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
