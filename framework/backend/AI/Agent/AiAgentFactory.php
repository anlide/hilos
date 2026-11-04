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
     *
     * @param SuperfanAiAgentId $agentId Identifier of the agent to build
     * @return AiAgentInterface The framework-level agent for that identifier
     * @throws LogicException When the identifier names an agent only a project can implement
     */
    public static function create(SuperfanAiAgentId $agentId): AiAgentInterface
    {
        return match ($agentId) {
            SuperfanAiAgentId::FORMATTER_LINTER_ORCHESTRATOR => new FormatterLinterOrchestratorAiAgent(),
            SuperfanAiAgentId::STATIC_ANALYSIS => new StaticAnalysisAiAgent(),
            SuperfanAiAgentId::PHPDOC_COMPLIANCE => new PhpDocComplianceAiAgent(),
            SuperfanAiAgentId::TEST_RUNNER => new TestRunnerAiAgent(),
            SuperfanAiAgentId::TEST_COVERAGE => new TestCoverageAiAgent(),
            SuperfanAiAgentId::TEST_QUALITY => new TestQualityAiAgent(),
            SuperfanAiAgentId::SAST => new SastAiAgent(),
            SuperfanAiAgentId::DEPENDENCY_VULNERABILITY => new DependencyVulnerabilityAiAgent(),
            SuperfanAiAgentId::ARCHITECTURE_COMPLIANCE => new ArchitectureComplianceAiAgent(),
            SuperfanAiAgentId::FRAMEWORK_USAGE => new FrameworkUsageAiAgent(),
            SuperfanAiAgentId::CODE_ORGANIZATION => new CodeOrganizationAiAgent(),

            SuperfanAiAgentId::SECRETS_CONFIG_LEAK,
            SuperfanAiAgentId::LOG_ANALYSIS,
            SuperfanAiAgentId::ANOMALY_DETECTION,
            SuperfanAiAgentId::DB_PERFORMANCE,
            SuperfanAiAgentId::MEMORY_CPU,
            SuperfanAiAgentId::API_LATENCY,
            SuperfanAiAgentId::SEO_TECHNICAL,
            SuperfanAiAgentId::FRONTEND_RENDERING,
            SuperfanAiAgentId::LINK_INTEGRITY,
            SuperfanAiAgentId::BUSINESS_LOGIC,
            SuperfanAiAgentId::PROMPT_AI_INTEGRATION,
            SuperfanAiAgentId::DOCKER_INFRA,
            SuperfanAiAgentId::CI_CD,
            SuperfanAiAgentId::DATA_INTEGRITY,
            SuperfanAiAgentId::MIGRATION_SAFETY,
            SuperfanAiAgentId::FEATURE_FLAG,
            SuperfanAiAgentId::OBSERVABILITY,
            SuperfanAiAgentId::DEAD_CODE,
            SuperfanAiAgentId::COST => throw new LogicException(
                "AI agent '{$agentId->value}' requires a project-level concrete implementation.",
            ),
        };
    }

    /**
     * Create all registered AI agents.
     *
     * @return array<string, AiAgentInterface>
     * @throws LogicException When an identifier names an agent only a project can implement
     */
    public static function createAll(): array
    {
        $agents = [];

        foreach (SuperfanAiAgentId::cases() as $agentId) {
            $agents[$agentId->value] = static::create($agentId);
        }

        return $agents;
    }
}
