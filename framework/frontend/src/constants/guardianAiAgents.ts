const guardianAiAgentCategoryById = {
  formatter_linter_orchestrator: 'Code Quality & Style',
  static_analysis: 'Code Quality & Style',
  phpdoc_compliance: 'Code Quality & Style',
  test_runner: 'Testing',
  test_coverage: 'Testing',
  test_quality: 'Testing',
  sast: 'Security',
  dependency_vulnerability: 'Security',
  secrets_config_leak: 'Security',
  log_analysis: 'Runtime Logs',
  anomaly_detection: 'Runtime Logs',
  db_performance: 'Performance',
  memory_cpu: 'Performance',
  api_latency: 'Performance',
  seo_technical: 'SEO & Web Integrity',
  frontend_rendering: 'SEO & Web Integrity',
  link_integrity: 'SEO & Web Integrity',
  business_logic: 'Application Level',
  prompt_ai_integration: 'Application Level',
  architecture_compliance: 'Framework Architecture',
  framework_usage: 'Framework Architecture',
  code_organization: 'Framework Architecture',
  docker_infra: 'DevOps Environment',
  ci_cd: 'DevOps Environment',
  data_integrity: 'Additional',
  migration_safety: 'Additional',
  feature_flag: 'Additional',
  observability: 'Additional',
  dead_code: 'Additional',
  cost: 'Additional',
  oss_budget_distribution: 'Open Source Governance'
} as const

export const guardianAiAgentIds = Object.keys(guardianAiAgentCategoryById) as Array<keyof typeof guardianAiAgentCategoryById>

export type GuardianAiAgentId = (typeof guardianAiAgentIds)[number]

const wordLabels: Record<string, string> = {
  ai: 'AI',
  api: 'API',
  oss: 'OSS',
  cd: 'CD',
  ci: 'CI',
  cpu: 'CPU',
  db: 'DB',
  phpdoc: 'PHPDoc',
  sast: 'SAST',
  seo: 'SEO'
}

const formatWord = (word: string): string => {
  return wordLabels[word] ?? `${word.slice(0, 1).toUpperCase()}${word.slice(1)}`
}

export const formatGuardianAiAgentTitle = (agentId: string): string => {
  return agentId
    .split('_')
    .map((word) => formatWord(word))
    .join(' ')
}

export const guardianAiAgents = guardianAiAgentIds.map((id) => ({
  id,
  title: formatGuardianAiAgentTitle(id),
  category: guardianAiAgentCategoryById[id]
}))

export const guardianAiAgentMap = Object.fromEntries(
  guardianAiAgents.map((agent) => [agent.id, agent])
) as Record<GuardianAiAgentId, (typeof guardianAiAgents)[number]>

export const isGuardianAiAgentId = (value: string): value is GuardianAiAgentId => {
  return guardianAiAgentIds.includes(value as GuardianAiAgentId)
}
