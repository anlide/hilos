import { isRecord } from '@/entities/parsers'

/**
 * Snapshot from HilosLogsOverviewSignalData (server subscription payload).
 */
export type HilosLogsOverviewSnapshot = {
  available: boolean
  totalRotationsAllTime: number | null
  lastRotationAt: string | null
}

/**
 * Parse WebSocket subscription payload for Hilos logs overview.
 */
export function parseHilosLogsOverviewPayload(raw: unknown): HilosLogsOverviewSnapshot | null {
  if (!isRecord(raw)) {
    return null
  }
  const available = raw['available'] === true
  const totalRaw = raw['totalRotationsAllTime']
  let totalRotationsAllTime: number | null = null
  if (typeof totalRaw === 'number' && Number.isFinite(totalRaw)) {
    totalRotationsAllTime = totalRaw
  } else if (typeof totalRaw === 'string' && totalRaw !== '' && Number.isFinite(Number(totalRaw))) {
    totalRotationsAllTime = Number(totalRaw)
  }
  const lastRaw = raw['lastRotationAt']
  const lastRotationAt = typeof lastRaw === 'string' && lastRaw !== '' ? lastRaw : null

  return {
    available,
    totalRotationsAllTime,
    lastRotationAt,
  }
}
