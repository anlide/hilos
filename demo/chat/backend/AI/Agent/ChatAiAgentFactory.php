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
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * ChatAiAgentFactory - Factory for chat project AI agents.
 */
final class ChatAiAgentFactory extends AiAgentFactory
{
    /**
     * Create one AI agent for the chat project.
     */
    public static function create(GuardianAiAgentId $agentId): AiAgentInterface
    {
        return match ($agentId) {
            GuardianAiAgentId::FORMATTER_LINTER_ORCHESTRATOR,
            GuardianAiAgentId::STATIC_ANALYSIS,
            GuardianAiAgentId::PHPDOC_COMPLIANCE,
            GuardianAiAgentId::TEST_RUNNER,
            GuardianAiAgentId::TEST_COVERAGE,
            GuardianAiAgentId::TEST_QUALITY,
            GuardianAiAgentId::SAST,
            GuardianAiAgentId::DEPENDENCY_VULNERABILITY,
            GuardianAiAgentId::ARCHITECTURE_COMPLIANCE,
            GuardianAiAgentId::FRAMEWORK_USAGE,
            GuardianAiAgentId::CODE_ORGANIZATION => parent::create($agentId),

            GuardianAiAgentId::SECRETS_CONFIG_LEAK => new ChatSecretsConfigLeakAiAgent(),
            GuardianAiAgentId::LOG_ANALYSIS => new ChatLogAnalysisAiAgent(),
            GuardianAiAgentId::ANOMALY_DETECTION => new ChatAnomalyDetectionAiAgent(),
            GuardianAiAgentId::DB_PERFORMANCE => new ChatDbPerformanceAiAgent(),
            GuardianAiAgentId::MEMORY_CPU => new ChatMemoryCpuAiAgent(),
            GuardianAiAgentId::API_LATENCY => new ChatApiLatencyAiAgent(),
            GuardianAiAgentId::SEO_TECHNICAL => new ChatSeoTechnicalAiAgent(),
            GuardianAiAgentId::FRONTEND_RENDERING => new ChatFrontendRenderingAiAgent(),
            GuardianAiAgentId::LINK_INTEGRITY => new ChatLinkIntegrityAiAgent(),
            GuardianAiAgentId::BUSINESS_LOGIC => new ChatBusinessLogicAiAgent(),
            GuardianAiAgentId::PROMPT_AI_INTEGRATION => new ChatPromptAiIntegrationAiAgent(),
            GuardianAiAgentId::DOCKER_INFRA => new ChatDockerInfraAiAgent(),
            GuardianAiAgentId::CI_CD => new ChatCiCdAiAgent(),
            GuardianAiAgentId::DATA_INTEGRITY => new ChatDataIntegrityAiAgent(),
            GuardianAiAgentId::MIGRATION_SAFETY => new ChatMigrationSafetyAiAgent(),
            GuardianAiAgentId::FEATURE_FLAG => new ChatFeatureFlagAiAgent(),
            GuardianAiAgentId::OBSERVABILITY => new ChatObservabilityAiAgent(),
            GuardianAiAgentId::DEAD_CODE => new ChatDeadCodeAiAgent(),
            GuardianAiAgentId::COST => new ChatCostAiAgent(),
        };
    }
}
