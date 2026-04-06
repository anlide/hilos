import { promises as fs } from 'node:fs';

const FALLBACK_GUARDIAN_ROLES = [
  'formatter_linter_orchestrator',
  'static_analysis',
  'phpdoc_compliance',
  'test_runner',
  'test_coverage',
  'test_quality',
  'sast',
  'dependency_vulnerability',
  'secrets_config_leak',
  'log_analysis',
  'anomaly_detection',
  'db_performance',
  'memory_cpu',
  'api_latency',
  'seo_technical',
  'frontend_rendering',
  'link_integrity',
  'business_logic',
  'prompt_ai_integration',
  'architecture_compliance',
  'framework_usage',
  'code_organization',
  'docker_infra',
  'ci_cd',
  'data_integrity',
  'migration_safety',
  'feature_flag',
  'observability',
  'dead_code',
  'cost',
] as const;

const CASE_PATTERN = /case\s+[A-Z0-9_]+\s*=\s*'([^']+)'/g;

export async function loadGuardianRoles(sourceFilePath: string): Promise<string[]> {
  try {
    const content = await fs.readFile(sourceFilePath, 'utf8');
    const roles = [...content.matchAll(CASE_PATTERN)].map((match) => match[1]).filter(Boolean);
    return roles.length > 0 ? roles : [...FALLBACK_GUARDIAN_ROLES];
  } catch {
    return [...FALLBACK_GUARDIAN_ROLES];
  }
}