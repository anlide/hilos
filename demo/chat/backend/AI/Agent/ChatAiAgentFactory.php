<?php

declare(strict_types=1);

namespace Demo\Chat\AI\Agent;

use Demo\Chat\AI\Agent\Additional\ChatCostAiAgent;
use Demo\Chat\AI\Agent\Additional\ChatDataIntegrityAiAgent;
use Demo\Chat\AI\Agent\Additional\ChatDeadCodeAiAgent;
use Demo\Chat\AI\Agent\Additional\ChatFeatureFlagAiAgent;
use Demo\Chat\AI\Agent\Additional\ChatMigrationSafetyAiAgent;
use Demo\Chat\AI\Agent\Additional\ChatObservabilityAiAgent;
use Demo\Chat\AI\Agent\ApplicationLevel\ChatBusinessLogicAiAgent;
use Demo\Chat\AI\Agent\ApplicationLevel\ChatPromptAiIntegrationAiAgent;
use Demo\Chat\AI\Agent\DevOpsEnvironment\ChatCiCdAiAgent;
use Demo\Chat\AI\Agent\DevOpsEnvironment\ChatDockerInfraAiAgent;
use Demo\Chat\AI\Agent\Performance\ChatApiLatencyAiAgent;
use Demo\Chat\AI\Agent\Performance\ChatDbPerformanceAiAgent;
use Demo\Chat\AI\Agent\Performance\ChatMemoryCpuAiAgent;
use Demo\Chat\AI\Agent\RuntimeLogs\ChatAnomalyDetectionAiAgent;
use Demo\Chat\AI\Agent\RuntimeLogs\ChatLogAnalysisAiAgent;
use Demo\Chat\AI\Agent\Security\ChatSecretsConfigLeakAiAgent;
use Demo\Chat\AI\Agent\SeoWebIntegrity\ChatFrontendRenderingAiAgent;
use Demo\Chat\AI\Agent\SeoWebIntegrity\ChatLinkIntegrityAiAgent;
use Demo\Chat\AI\Agent\SeoWebIntegrity\ChatSeoTechnicalAiAgent;
use Hilos\AI\Agent\AiAgentFactory;
use Hilos\AI\Agent\AiAgentInterface;
use Hilos\AI\Agent\SuperfanAiAgentId;
use LogicException;

/**
 * ChatAiAgentFactory - Factory for chat project AI agents.
 */
final class ChatAiAgentFactory extends AiAgentFactory
{
    /**
     * Create one AI agent for the chat project.
     *
     * @param SuperfanAiAgentId $agentId Superfan agent identifier
     * @return AiAgentInterface Framework or chat superfan agent
     * @throws LogicException When an internal invariant is violated
     */
    public static function create(SuperfanAiAgentId $agentId): AiAgentInterface
    {
        return match ($agentId) {
            SuperfanAiAgentId::FORMATTER_LINTER_ORCHESTRATOR,
            SuperfanAiAgentId::STATIC_ANALYSIS,
            SuperfanAiAgentId::PHPDOC_COMPLIANCE,
            SuperfanAiAgentId::TEST_RUNNER,
            SuperfanAiAgentId::TEST_COVERAGE,
            SuperfanAiAgentId::TEST_QUALITY,
            SuperfanAiAgentId::SAST,
            SuperfanAiAgentId::DEPENDENCY_VULNERABILITY,
            SuperfanAiAgentId::ARCHITECTURE_COMPLIANCE,
            SuperfanAiAgentId::FRAMEWORK_USAGE,
            SuperfanAiAgentId::CODE_ORGANIZATION => parent::create($agentId),

            SuperfanAiAgentId::SECRETS_CONFIG_LEAK => new ChatSecretsConfigLeakAiAgent(),
            SuperfanAiAgentId::LOG_ANALYSIS => new ChatLogAnalysisAiAgent(),
            SuperfanAiAgentId::ANOMALY_DETECTION => new ChatAnomalyDetectionAiAgent(),
            SuperfanAiAgentId::DB_PERFORMANCE => new ChatDbPerformanceAiAgent(),
            SuperfanAiAgentId::MEMORY_CPU => new ChatMemoryCpuAiAgent(),
            SuperfanAiAgentId::API_LATENCY => new ChatApiLatencyAiAgent(),
            SuperfanAiAgentId::SEO_TECHNICAL => new ChatSeoTechnicalAiAgent(),
            SuperfanAiAgentId::FRONTEND_RENDERING => new ChatFrontendRenderingAiAgent(),
            SuperfanAiAgentId::LINK_INTEGRITY => new ChatLinkIntegrityAiAgent(),
            SuperfanAiAgentId::BUSINESS_LOGIC => new ChatBusinessLogicAiAgent(),
            SuperfanAiAgentId::PROMPT_AI_INTEGRATION => new ChatPromptAiIntegrationAiAgent(),
            SuperfanAiAgentId::DOCKER_INFRA => new ChatDockerInfraAiAgent(),
            SuperfanAiAgentId::CI_CD => new ChatCiCdAiAgent(),
            SuperfanAiAgentId::DATA_INTEGRITY => new ChatDataIntegrityAiAgent(),
            SuperfanAiAgentId::MIGRATION_SAFETY => new ChatMigrationSafetyAiAgent(),
            SuperfanAiAgentId::FEATURE_FLAG => new ChatFeatureFlagAiAgent(),
            SuperfanAiAgentId::OBSERVABILITY => new ChatObservabilityAiAgent(),
            SuperfanAiAgentId::DEAD_CODE => new ChatDeadCodeAiAgent(),
            SuperfanAiAgentId::COST => new ChatCostAiAgent(),
        };
    }
}
