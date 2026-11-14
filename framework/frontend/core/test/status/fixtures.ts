import { vi } from 'vitest'
import { type WebSocketLike } from '../../src/connection/HilosConnection.js'
import {
  type PublicStatusFile,
  type PublicStatusHeartbeat,
  type PublicStatusMaintenancePeriod,
  type PublicStatusOutage,
  type PublicStatusWork,
} from '../../src/status/index.js'

export const NOW = Date.parse('2026-10-25T12:00:00Z')

/** Observation began before every day these tests draw, so none of them is empty. */
export const OBSERVED_SINCE = Date.parse('2025-01-01T00:00:00Z')

type CurrentStatusFile = Extract<PublicStatusFile, { schemaVersion: 4 }>
type CurrentStatusComponent = CurrentStatusFile['components'][number]

/** A v4 catalog entry, observed since long before the visible window. */
export function component(
  overrides: Partial<CurrentStatusComponent> = {},
): CurrentStatusComponent {
  return {
    componentKey: 'site',
    name: 'Site',
    description: null,
    state: 'operational',
    observedSince: OBSERVED_SINCE,
    ...overrides,
  }
}

export function statusFile(
  overrides: Partial<CurrentStatusFile> = {},
): CurrentStatusFile {
  return {
    schemaVersion: 4,
    revision: 'opaque/revision',
    generatedAt: NOW - 5000,
    config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
    components: [component()],
    candidates: [],
    outages: [],
    scheduledWorks: [],
    maintenancePeriods: [],
    silencePeriods: [],
    ...overrides,
  }
}

/**
 * A literal legacy file: no component carries its observation moment, and v1
 * and v2 lack the gap list.
 */
export function legacyStatusFile(
  schemaVersion: 1 | 2 | 3,
  overrides: Partial<CurrentStatusFile> = {},
): PublicStatusFile {
  const status = statusFile(overrides)
  const legacy = {
    revision: status.revision,
    generatedAt: status.generatedAt,
    config: status.config,
    components: status.components.map((entry) => ({
      componentKey: entry.componentKey,
      name: entry.name,
      description: entry.description,
      state: entry.state,
    })),
    candidates: status.candidates,
    outages: status.outages,
    scheduledWorks: status.scheduledWorks,
    maintenancePeriods: status.maintenancePeriods,
  }
  return schemaVersion === 3
    ? { ...legacy, schemaVersion, silencePeriods: status.silencePeriods }
    : { ...legacy, schemaVersion }
}

export function heartbeat(
  overrides: Partial<PublicStatusHeartbeat> = {},
): PublicStatusHeartbeat {
  return {
    schemaVersion: 4,
    revision: 'opaque/revision',
    aliveAt: NOW,
    ...overrides,
  }
}

export function outage(
  overrides: Partial<PublicStatusOutage> = {},
): PublicStatusOutage {
  return {
    id: 'arbitrary id',
    componentKey: 'site',
    severity: 'major_outage',
    detectedAt: NOW - 120000,
    publishedAt: NOW - 60000,
    resolvedAt: null,
    updates: [],
    ...overrides,
  }
}

/** An announcement whose window has not begun at the fixture reference moment. */
export function work(
  overrides: Partial<PublicStatusWork> = {},
): PublicStatusWork {
  return {
    id: 'opaque work',
    title: 'Database upgrade',
    description: 'The database moves to a newer version.',
    componentKeys: ['site'],
    startsAt: NOW + 1000,
    endsAt: NOW + 10000,
    ...overrides,
  }
}

/** A known unannounced actual period, still active in the accepted projection. */
export function maintenance(
  overrides: Partial<PublicStatusMaintenancePeriod> = {},
): PublicStatusMaintenancePeriod {
  return {
    id: 'opaque period',
    workId: null,
    announcementKnown: true,
    title: 'Maintenance',
    componentKeys: [],
    startedAt: NOW - 1000,
    lastObservedAt: NOW,
    endedAt: null,
    completionObservedAt: null,
    ...overrides,
  }
}

export function response(
  value: unknown,
  ok = true,
): Pick<Response, 'ok' | 'json'> {
  return { ok, json: async () => value }
}

/** This socket never receives a welcome unless a test deliberately supplies one. */
export class Socket implements WebSocketLike {
  readonly readyState = 1
  readonly send = vi.fn()
  readonly close = vi.fn()
  private readonly listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()

  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    const list = this.listeners.get(type) ?? []
    list.push(listener)
    this.listeners.set(type, list)
  }

  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) listener(event)
  }

  push(type: string, data: unknown = {}): void {
    this.emit('message', { data: JSON.stringify({ type, data }) })
  }
}

export function deferred<T>(): {
  promise: Promise<T>
  resolve: (value: T) => void
} {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((accept) => {
    resolve = accept
  })

  return { promise, resolve }
}
