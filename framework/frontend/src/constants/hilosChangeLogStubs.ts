/** Stub data for Hilos Change Log section (demo only). */
export const hilosChangeLogDashboardStub = {
  triggersExpected: 12,
  triggersActive: 9,
  deviationsCount: 3,
  retentionLabel: '100 days',
} as const

export const hilosChangeLogTablesStub = [
  {
    tableId: 'user',
    displayName: 'user',
    expectedTriggers: 3,
    activeTriggers: 3,
    status: 'ok' as const,
  },
  {
    tableId: 'hilos_setting',
    displayName: 'hilos_setting',
    expectedTriggers: 3,
    activeTriggers: 2,
    status: 'mismatch' as const,
  },
  {
    tableId: 'bot',
    displayName: 'bot',
    expectedTriggers: 3,
    activeTriggers: 0,
    status: 'missing' as const,
  },
] as const

export const hilosChangeLogTableDetailStub: Record<
  string,
  { fields: { name: string; trackValues: boolean; triggerOk: boolean }[] }
> = {
  user: {
    fields: [
      { name: 'name', trackValues: true, triggerOk: true },
      { name: 'admin', trackValues: true, triggerOk: true },
      { name: 'theme', trackValues: false, triggerOk: true },
    ],
  },
  hilos_setting: {
    fields: [
      { name: 'value', trackValues: true, triggerOk: false },
      { name: 'type', trackValues: true, triggerOk: true },
    ],
  },
  bot: {
    fields: [
      { name: 'name', trackValues: true, triggerOk: false },
      { name: 'reaction_settings', trackValues: true, triggerOk: false },
    ],
  },
}
