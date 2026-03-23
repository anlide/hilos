/** Stub log statistics and lists (demo only). Rotation counts come from WebSocket subscription. */
export const hilosLogsOverviewStub = {
  logKeysPerAgent: 24,
  totalWeightAgentKeysBytes: 4_128_000,
  logKeysPerWorker: 9,
  totalWeightWorkerKeysBytes: 1_024_000,
} as const

export const hilosLogsByKeyStub = [
  { key: 'app.info', weightBytes: 512_000 },
  { key: 'daemon.cron', weightBytes: 128_000 },
  { key: 'agent.trace', weightBytes: 2_048_000 },
] as const

export const hilosLogsByWorkerStub = [
  { workerId: 'w-1', weightBytes: 640_000 },
  { workerId: 'w-2', weightBytes: 256_000 },
  { workerId: 'w-mono-1', weightBytes: 128_000 },
] as const

export const hilosLogsRotationsStub = [
  { rotationId: 1284, at: '2025-03-22T04:00:00Z', totalBytes: 5_152_000 },
  { rotationId: 1283, at: '2025-03-21T04:00:00Z', totalBytes: 4_900_000 },
  { rotationId: 1282, at: '2025-03-20T04:00:00Z', totalBytes: 4_750_000 },
] as const

export type HilosLogViewerLine = {
  line: string
  key: string
  workerId: string
  rotationId: number
}

export const hilosLogsViewerLinesStub: HilosLogViewerLine[] = [
  {
    line: '[INFO] worker=w-1 key=app.info rotation=1284 startup complete',
    key: 'app.info',
    workerId: 'w-1',
    rotationId: 1284,
  },
  {
    line: '[DEBUG] worker=w-2 key=agent.trace rotation=1284 tick',
    key: 'agent.trace',
    workerId: 'w-2',
    rotationId: 1284,
  },
  {
    line: '[WARN] worker=w-mono-1 key=daemon.cron rotation=1283 slow job',
    key: 'daemon.cron',
    workerId: 'w-mono-1',
    rotationId: 1283,
  },
]
