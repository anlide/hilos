<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

/**
 * GuardianAiAgentCategory - High-level guardian AI agent categories.
 */
enum GuardianAiAgentCategory: string implements AiAgentCategoryInterface
{
    case CODE_QUALITY_STYLE = 'code_quality_style';
    case TESTING = 'testing';
    case SECURITY = 'security';
    case RUNTIME_LOGS = 'runtime_logs';
    case PERFORMANCE = 'performance';
    case SEO_WEB_INTEGRITY = 'seo_web_integrity';
    case APPLICATION_LEVEL = 'application_level';
    case FRAMEWORK_ARCHITECTURE = 'framework_architecture';
    case DEVOPS_ENVIRONMENT = 'devops_environment';
    case ADDITIONAL = 'additional';
}
