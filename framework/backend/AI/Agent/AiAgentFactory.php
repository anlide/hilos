<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

use Hilos\AI\Agent\CodeQuality\FormatterLinterOrchestratorAiAgent;
use Hilos\AI\Agent\CodeQuality\PhpDocComplianceAiAgent;
use Hilos\AI\Agent\CodeQuality\StaticAnalysisAiAgent;
use Hilos\AI\Agent\FrameworkArchitecture\ArchitectureComplianceAiAgent;
use Hilos\AI\Agent\FrameworkArchitecture\CodeOrganizationAiAgent;
use Hilos\AI\Agent\FrameworkArchitecture\FrameworkUsageAiAgent;
use Hilos\AI\Agent\Security\DependencyVulnerabilityAiAgent;
use Hilos\AI\Agent\Security\SastAiAgent;
use Hilos\AI\Agent\Testing\TestCoverageAiAgent;
use Hilos\AI\Agent\Testing\TestQualityAiAgent;
use Hilos\AI\Agent\Testing\TestRunnerAiAgent;
use LogicException;

/**
 * AiAgentFactory - Factory for framework-level AI agents.
 */
class AiAgentFactory
{
    /**
     * Create one AI agent by its identifier.
     */
    public static function create(GuardianAiAgentId $agentId): AiAgentInterface
    {
        return match ($agentId) {
            GuardianAiAgentId::FORMATTER_LINTER_ORCHESTRATOR => new FormatterLinterOrchestratorAiAgent(),
            GuardianAiAgentId::STATIC_ANALYSIS => new StaticAnalysisAiAgent(),
            GuardianAiAgentId::PHPDOC_COMPLIANCE => new PhpDocComplianceAiAgent(),
            GuardianAiAgentId::TEST_RUNNER => new TestRunnerAiAgent(),
            GuardianAiAgentId::TEST_COVERAGE => new TestCoverageAiAgent(),
            GuardianAiAgentId::TEST_QUALITY => new TestQualityAiAgent(),
            GuardianAiAgentId::SAST => new SastAiAgent(),
            GuardianAiAgentId::DEPENDENCY_VULNERABILITY => new DependencyVulnerabilityAiAgent(),
            GuardianAiAgentId::ARCHITECTURE_COMPLIANCE => new ArchitectureComplianceAiAgent(),
            GuardianAiAgentId::FRAMEWORK_USAGE => new FrameworkUsageAiAgent(),
            GuardianAiAgentId::CODE_ORGANIZATION => new CodeOrganizationAiAgent(),

            GuardianAiAgentId::SECRETS_CONFIG_LEAK,
            GuardianAiAgentId::LOG_ANALYSIS,
            GuardianAiAgentId::ANOMALY_DETECTION,
            GuardianAiAgentId::DB_PERFORMANCE,
            GuardianAiAgentId::MEMORY_CPU,
            GuardianAiAgentId::API_LATENCY,
            GuardianAiAgentId::SEO_TECHNICAL,
            GuardianAiAgentId::FRONTEND_RENDERING,
            GuardianAiAgentId::LINK_INTEGRITY,
            GuardianAiAgentId::BUSINESS_LOGIC,
            GuardianAiAgentId::PROMPT_AI_INTEGRATION,
            GuardianAiAgentId::DOCKER_INFRA,
            GuardianAiAgentId::CI_CD,
            GuardianAiAgentId::DATA_INTEGRITY,
            GuardianAiAgentId::MIGRATION_SAFETY,
            GuardianAiAgentId::FEATURE_FLAG,
            GuardianAiAgentId::OBSERVABILITY,
            GuardianAiAgentId::DEAD_CODE,
            GuardianAiAgentId::COST => throw new LogicException(
                "AI agent '{$agentId->value}' requires a project-level concrete implementation.",
            ),
        };
    }

    /**
     * Create all registered AI agents.
     *
     * @return array<string, AiAgentInterface>
     */
    public static function createAll(): array
    {
        $agents = [];

        foreach (GuardianAiAgentId::cases() as $agentId) {
            $agents[$agentId->value] = static::create($agentId);
        }

        return $agents;
    }
}
