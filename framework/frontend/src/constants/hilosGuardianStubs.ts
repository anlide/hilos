/**
 * Stub content for Guardian OSS budget distribution agent (demo).
 */

export type HilosOssBudgetAllocationRow = {
  packageName: string
  score: number
  recommendedUsd: number
  rationale: string
}

export const hilosOssBudgetPoolStub = {
  annualBudgetUsd: 12_000,
  fiscalYearLabel: 'FY2025',
  spentUsd: 3_200,
  remainingUsd: 8_800,
}

export const hilosOssBudgetTopDependenciesStub: HilosOssBudgetAllocationRow[] = [
  {
    packageName: 'symfony/http-foundation',
    score: 94,
    recommendedUsd: 2_400,
    rationale: 'High runtime usage + security-critical path',
  },
  {
    packageName: 'laravel/framework',
    score: 88,
    recommendedUsd: 1_800,
    rationale: 'Core app stack, frequent updates',
  },
  {
    packageName: 'guzzlehttp/guzzle',
    score: 76,
    recommendedUsd: 900,
    rationale: 'Outbound HTTP across integrations',
  },
]

export const hilosOssBudgetRulesStub = [
  'Usage: weighted by call graph and production traffic (stub)',
  'Criticality: security / auth / crypto deps boosted',
  'Maintenance risk: stale releases and open CVEs penalized',
  'Manual adjustments: committee can override with audit trail (future)',
]
