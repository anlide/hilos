<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Guardian\Capabilities\DbEventsReadCapability;
use Demo\Chat\Guardian\Capabilities\DbReadCapability;
use Demo\Chat\Guardian\Capabilities\NotesReadWriteCapability;
use Demo\Chat\Guardian\Capabilities\RtReadCapability;
use Demo\Chat\Guardian\Config\GuardianConfig;
use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Hilos\Utils\Logger;

/**
 * GuardiansOpsAgent - Monopolistic guardian for ops heartbeat
 *
 * Reports ops metrics (messages, connections, bots) periodically.
 */
final class GuardiansOpsAgent extends AbstractGuardianAgent
{
    private float $nextRunAtMs = 0.0;
    private DbEventsReadCapability $eventsCapability;
    private DbReadCapability $dbCapability;
    private RtReadCapability $rtCapability;
    private NotesReadWriteCapability $notesCapability;

    /**
     * Create GuardiansOpsAgent with required capabilities.
     */
    public function __construct()
    {
        parent::__construct();
        $this->eventsCapability = new DbEventsReadCapability();
        $this->dbCapability = new DbReadCapability();
        $this->rtCapability = new RtReadCapability();
        $this->notesCapability = new NotesReadWriteCapability();
    }

    /**
     * Get agent type identifier.
     *
     * @return string Agent type
     */
    public function getType(): string
    {
        return AgentType::GUARDIAN_OPS;
    }

    /**
     * Get agent index (null for global guardian ops agent).
     *
     * @return ?string Agent index or null
     */
    public function getIndex(): ?string
    {
        return null;
    }

    /**
     * Called when agent is started.
     */
    public function onStart(): void
    {
        Logger::logAgentStart($this->getId(), $this->getType());
    }

    public function onStop(): void
    {
        Logger::logAgentStop($this->getId(), $this->getType());
    }

    /**
     * Periodic tick: run capabilities, build report, publish.
     */
    public function onTick(): void
    {
        $nowMs = microtime(true) * 1000;
        if ($nowMs < $this->nextRunAtMs) {
            return;
        }
        $this->nextRunAtMs = $nowMs + GuardianConfig::OPS_TICK_INTERVAL_MS;

        $eventsResult = $this->eventsCapability->execute(['limit' => 30]);
        $dbResult = $this->dbCapability->execute(['limitPerCollection' => 20]);
        $rtResult = $this->rtCapability->execute(['limitPerCollection' => 30]);
        if (!$eventsResult->ok || !$dbResult->ok || !$rtResult->ok) {
            Logger::logAgentError($this->getId(), 'Guardian ops capability read failed');
            return;
        }

        $events = $eventsResult->data['events'] ?? [];
        $messageEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['type'] ?? '') === 'message_sent'
        ));
        $recentMessages = count($messageEvents);
        $activeConnections = (int) (($rtResult->data['rt']['connections']['count'] ?? 0));
        $botsCount = (int) (($dbResult->data['db']['bots']['count'] ?? 0));

        $severity = $recentMessages > 40 ? 'medium' : 'info';
        $title = 'Ops heartbeat';
        $message = "Recent messages={$recentMessages}; activeConnections={$activeConnections}; bots={$botsCount}";

        $this->notesCapability->execute([
            'mode' => 'write',
            'scope' => 'guardian_ops',
            'note' => '[' . date('c') . "] {$message}",
        ]);

        $this->publishReport(
            new GuardianReportPayload(
                guardian: AgentType::GUARDIAN_OPS,
                category: 'logs_security',
                severity: $severity,
                title: $title,
                message: $message,
                details: [
                    'recentMessages' => $recentMessages,
                    'activeConnections' => $activeConnections,
                    'botsCount' => $botsCount,
                ],
            )
        );
    }
}
