<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

/**
 * SuperfanAiAgentId - Stable identifiers for all superfan AI agents.
 */
enum SuperfanAiAgentId: string implements AiAgentIdInterface
{
    case FORMATTER_LINTER_ORCHESTRATOR = 'formatter_linter_orchestrator';
    case STATIC_ANALYSIS = 'static_analysis';
    case PHPDOC_COMPLIANCE = 'phpdoc_compliance';
    case TEST_RUNNER = 'test_runner';
    case TEST_COVERAGE = 'test_coverage';
    case TEST_QUALITY = 'test_quality';
    case SAST = 'sast';
    case DEPENDENCY_VULNERABILITY = 'dependency_vulnerability';
    case SECRETS_CONFIG_LEAK = 'secrets_config_leak';
    case LOG_ANALYSIS = 'log_analysis';
    case ANOMALY_DETECTION = 'anomaly_detection';
    case DB_PERFORMANCE = 'db_performance';
    case MEMORY_CPU = 'memory_cpu';
    case API_LATENCY = 'api_latency';
    case SEO_TECHNICAL = 'seo_technical';
    case FRONTEND_RENDERING = 'frontend_rendering';
    case LINK_INTEGRITY = 'link_integrity';
    case BUSINESS_LOGIC = 'business_logic';
    case PROMPT_AI_INTEGRATION = 'prompt_ai_integration';
    case ARCHITECTURE_COMPLIANCE = 'architecture_compliance';
    case FRAMEWORK_USAGE = 'framework_usage';
    case CODE_ORGANIZATION = 'code_organization';
    case DOCKER_INFRA = 'docker_infra';
    case CI_CD = 'ci_cd';
    case DATA_INTEGRITY = 'data_integrity';
    case MIGRATION_SAFETY = 'migration_safety';
    case FEATURE_FLAG = 'feature_flag';
    case OBSERVABILITY = 'observability';
    case DEAD_CODE = 'dead_code';
    case COST = 'cost';

    /**
     * Get high-level category for the agent.
     */
    public function category(): SuperfanAiAgentCategory
    {
        return match ($this) {
            self::FORMATTER_LINTER_ORCHESTRATOR,
            self::STATIC_ANALYSIS,
            self::PHPDOC_COMPLIANCE => SuperfanAiAgentCategory::CODE_QUALITY_STYLE,

            self::TEST_RUNNER,
            self::TEST_COVERAGE,
            self::TEST_QUALITY => SuperfanAiAgentCategory::TESTING,

            self::SAST,
            self::DEPENDENCY_VULNERABILITY,
            self::SECRETS_CONFIG_LEAK => SuperfanAiAgentCategory::SECURITY,

            self::LOG_ANALYSIS,
            self::ANOMALY_DETECTION => SuperfanAiAgentCategory::RUNTIME_LOGS,

            self::DB_PERFORMANCE,
            self::MEMORY_CPU,
            self::API_LATENCY => SuperfanAiAgentCategory::PERFORMANCE,

            self::SEO_TECHNICAL,
            self::FRONTEND_RENDERING,
            self::LINK_INTEGRITY => SuperfanAiAgentCategory::SEO_WEB_INTEGRITY,

            self::BUSINESS_LOGIC,
            self::PROMPT_AI_INTEGRATION => SuperfanAiAgentCategory::APPLICATION_LEVEL,

            self::ARCHITECTURE_COMPLIANCE,
            self::FRAMEWORK_USAGE,
            self::CODE_ORGANIZATION => SuperfanAiAgentCategory::FRAMEWORK_ARCHITECTURE,

            self::DOCKER_INFRA,
            self::CI_CD => SuperfanAiAgentCategory::DEVOPS_ENVIRONMENT,

            self::DATA_INTEGRITY,
            self::MIGRATION_SAFETY,
            self::FEATURE_FLAG,
            self::OBSERVABILITY,
            self::DEAD_CODE,
            self::COST => SuperfanAiAgentCategory::ADDITIONAL,
        };
    }
}
