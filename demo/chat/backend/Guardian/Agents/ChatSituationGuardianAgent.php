<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Agents;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Guardian\Capabilities\DbEventsReadCapability;
use Demo\Chat\Guardian\Capabilities\RtReadCapability;
use Demo\Chat\Guardian\Config\GuardianConfig;
use Demo\Chat\Guardian\Reports\GuardianReportPayload;
use Demo\Chat\Utils\ContextAnalyzerEnv;
use Hilos\LLM\ClientFactory;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\Utils\Helpers\JsonHelper;

/**
 * ChatSituationGuardianAgent - Guardian agent for chat situation analysis.
 *
 * Runs periodically to analyze chat health and publish insights via Guardian report.
 */
final class ChatSituationGuardianAgent extends AbstractGuardianAgent
{
    public const string AGENT_TYPE = AgentType::CHAT_SITUATION_GUARDIAN;

    /** @var float Next scheduled run timestamp in milliseconds */
    private float $nextRunAtMs = 0.0;

    /** @var DbEventsReadCapability Events read capability */
    private DbEventsReadCapability $eventsCapability;

    /** @var RtReadCapability Runtime read capability */
    private RtReadCapability $rtCapability;

    /** @var AsyncChatLLMInterface LLM chat client for analysis */
    private AsyncChatLLMInterface $chatClient;

    /** @var array<string, mixed>|null pending stats for report after LLM response */
    private ?array $pendingStats = null;

    /**
     * Create guardian agent with DB, RT and LLM capabilities.
     */
    public function __construct()
    {
        parent::__construct();
        $this->eventsCapability = new DbEventsReadCapability();
        $this->rtCapability = new RtReadCapability();
        $this->chatClient = ContextAnalyzerEnv::useExternalProvider()
            ? ClientFactory::createChatClient()
            : ClientFactory::createChatClientWithConfig(
                url: ContextAnalyzerEnv::getUrl(),
                model: ContextAnalyzerEnv::getModel(),
                apiKey: null,
            );
    }

    /**
     * Agent-specific tick. Processes LLM results and schedules next analysis run.
     */
    public function onTick(): void
    {
        $this->chatClient->tick(microtime(true) * 1000);
        if ($this->chatClient->hasResult()) {
            $this->finishPendingReport($this->chatClient->getResult());
        }

        if ($this->chatClient->isBusy()) {
            return;
        }

        $nowMs = microtime(true) * 1000;
        if ($nowMs < $this->nextRunAtMs) {
            return;
        }
        $this->nextRunAtMs = $nowMs + GuardianConfig::CHAT_SITUATION_TICK_INTERVAL_MS;

        $stats = $this->buildChatStats();
        if ($stats === null) {
            return;
        }

        $summaryPrompt = $this->buildPromptFromStats($stats);
        $options = new ChatGenerateOptions(
            model: ContextAnalyzerEnv::getModel(),
            temperature: 0.0,
            timeoutSec: ContextAnalyzerEnv::getTimeoutSec(),
            maxTokens: 80,
        );
        $messages = [
            new Message(Message::ROLE_SYSTEM, 'Return JSON only: {"severity":"info|low|medium|high","message":"short admin insight"}'),
            new Message(Message::ROLE_USER, $summaryPrompt),
        ];

        if (!$this->chatClient->startGenerate($messages, $options)) {
            return;
        }

        $this->pendingStats = $stats;
    }

    /**
     * Build chat stats from events and RT data for LLM prompt.
     *
     * @return array<string, mixed>|null Stats (messages, uniqueAuthors, activeConnections, recentTypes) or null on error
     */
    private function buildChatStats(): ?array
    {
        $eventsResult = $this->eventsCapability->execute(['limit' => GuardianConfig::MAX_EVENTS_SCAN]);
        $rtResult = $this->rtCapability->execute(['limitPerCollection' => 50]);
        if (!$eventsResult->ok || !$rtResult->ok) {
            return null;
        }

        $events = is_array($eventsResult->data['events'] ?? null) ? $eventsResult->data['events'] : [];
        $messageEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => ($event['type'] ?? '') === ChatEventType::MESSAGE_SENT->value
        ));
        $uniqueAuthors = [];
        foreach ($messageEvents as $event) {
            $key = ($event['userId'] ?? null) !== null
                ? 'u:' . (string) $event['userId']
                : 'b:' . (string) ($event['botId'] ?? 0);
            $uniqueAuthors[$key] = true;
        }

        return [
            'messages' => count($messageEvents),
            'uniqueAuthors' => count($uniqueAuthors),
            'activeConnections' => (int) (($rtResult->data['rt']['connections']['count'] ?? 0)),
            'recentTypes' => array_values(array_unique(array_map(
                static fn (array $event): string => (string) ($event['type'] ?? ''),
                array_slice($events, -10),
            ))),
        ];
    }

    /**
     * Build LLM prompt string from chat stats.
     *
     * @param array<string, mixed> $stats Chat statistics
     * @return string Prompt text for LLM
     */
    private function buildPromptFromStats(array $stats): string
    {
        return 'Analyze chat health from stats: ' . json_encode($stats, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Finishes pending report and publishes it to Guardian.
     *
     * @param ?string $rawResult LLM raw response or null
     */
    private function finishPendingReport(?string $rawResult): void
    {
        $stats = $this->pendingStats;
        $this->pendingStats = null;
        if ($stats === null) {
            return;
        }

        $severity = 'info';
        $message = 'Chat activity analyzed';
        if (is_string($rawResult) && $rawResult !== '') {
            $candidate = JsonHelper::extractJsonObject($rawResult);
            if ($candidate !== null) {
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    $severity = is_string($decoded['severity'] ?? null) ? $decoded['severity'] : $severity;
                    $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : $message;
                }
            }
        }

        $this->publishReport(
            new GuardianReportPayload(
                guardian: AgentType::CHAT_SITUATION_GUARDIAN,
                category: 'business',
                severity: $severity,
                title: 'Chat situation insight',
                message: $message,
                details: $stats,
            )
        );
    }
}
