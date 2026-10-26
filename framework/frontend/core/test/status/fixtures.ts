import { vi } from 'vitest'
import { type WebSocketLike } from '../../src/connection/HilosConnection.js'
import {
  type PublicStatusFile,
  type PublicStatusHeartbeat,
  type PublicStatusOutage,
} from '../../src/status/index.js'

export const NOW = Date.parse('2026-10-25T12:00:00Z')

export function statusFile(
  overrides: Partial<PublicStatusFile> = {},
): PublicStatusFile {
  return {
    schemaVersion: 1,
    revision: 'opaque/revision',
    generatedAt: NOW - 5000,
    config: { pollIntervalSeconds: 30, staleAfterSeconds: 120 },
    components: [
      {
        componentKey: 'site',
        name: 'Site',
        description: null,
        state: 'operational',
      },
    ],
    candidates: [],
    outages: [],
    scheduledWorks: [],
    maintenancePeriods: [],
    ...overrides,
  }
}

export function heartbeat(
  overrides: Partial<PublicStatusHeartbeat> = {},
): PublicStatusHeartbeat {
  return {
    schemaVersion: 1,
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
