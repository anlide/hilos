import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  type ConnectionState,
  type HilosConnection,
  type HilosConnectionEventMap,
  type HilosRestoreStatus,
  type ProtectedModeStatus,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

export const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

export const FROZEN_WITHOUT_COPY: ProtectedModeStatus = {
  active: true,
  operation: undefined,
  title: undefined,
  message: undefined,
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

export const VERIFYING: ProtectedModeStatus = { ...FROZEN, acceptsPass: true }
export const MINTED: ProtectedModeStatus = { ...VERIFYING, passIssued: true }
export const REJECTED: ProtectedModeStatus = { ...MINTED, passRejected: true }

/** A raw addressed frame, with no estimate or terminal outcome by default. */
export function restoreFrame(
  overrides: Partial<HilosRestoreStatus> = {},
): HilosRestoreStatus {
  return {
    running: true,
    backupId: '2026-08-24-full',
    scope: 'full',
    phase: 'importing',
    phaseStartedAt: null,
    startedAt: null,
    finishedAt: null,
    outcome: null,
    failureReason: null,
    estimatedSeconds: null,
    rehydrateComplete: true,
    rehydrateProblems: [],
    databaseTouched: false,
    ...overrides,
  }
}

/** Actual standalone component inputs, without shell or session machinery. */
export interface MaintenanceInput {
  status: ProtectedModeStatus
  connection: HilosConnection
  adminSurface: boolean
}

/** Native operations receive controls selected by the shared scenario. */
export interface MaintenanceView {
  readonly root: HTMLElement
  update(
    patch: Partial<Pick<MaintenanceInput, 'status' | 'adminSurface'>>,
  ): Promise<void>
  flush(run?: () => void): Promise<void>
  input(control: HTMLInputElement, value: string): Promise<void>
  submit(form: HTMLFormElement): Promise<void>
  unmount(): Promise<void>
}

/** Retain the partial view before setup so even a failed mount can be released. */
export type MountMaintenance = (
  input: MaintenanceInput,
  retain: (view: MaintenanceView) => void,
) => Promise<MaintenanceView>

/** A raw connection boundary: synchronous pass calls and removable listeners. */
export function maintenanceFeed(
  initial: ProtectedModeStatus,
  initialState: ConnectionState = 'connected',
) {
  let status = initial
  const presented: string[] = []
  const listeners = new Map<
    keyof HilosConnectionEventMap,
    Set<(payload: never) => void>
  >()
  function emit<E extends keyof HilosConnectionEventMap>(
    event: E,
    payload: HilosConnectionEventMap[E],
  ): void {
    const errors: unknown[] = []
    for (const listener of listeners.get(event) ?? []) {
      try {
        listener(payload as never)
      } catch (error) {
        errors.push(error)
      }
    }
    if (errors.length) throw new AggregateError(errors)
  }
  const source = {
    get state() {
      return initialState
    },
    get protectedMode() {
      return status
    },
    on(event, listener) {
      const subscriptions = listeners.get(event) ?? new Set()
      listeners.set(event, subscriptions)
      const notify = (payload: never): void => listener(payload)
      subscriptions.add(notify)
      return () => {
        subscriptions.delete(notify)
      }
    },
    presentProtectedModePass(rawCode) {
      presented.push(rawCode)
    },
  } satisfies Pick<
    HilosConnection,
    'state' | 'protectedMode' | 'on' | 'presentProtectedModePass'
  >

  return {
    connection: source as HilosConnection,
    presented,
    pushStatus(next: ProtectedModeStatus): void {
      status = next
      emit('protectedMode', next)
    },
    pushRestore(frame: HilosRestoreStatus): void {
      emit('projectSignal', {
        kind: 'project',
        type: BACKUP_RESTORE_PROGRESS_SIGNAL,
        data: frame,
        envelope: { type: BACKUP_RESTORE_PROGRESS_SIGNAL, data: frame },
      })
    },
    dispose(): void {
      listeners.clear()
    },
  }
}

/** Attempt every native release and keep all errors available to the caller. */
export async function releaseMaintenance(
  releases: (() => void | Promise<void>)[],
): Promise<void> {
  const errors: unknown[] = []
  for (const release of releases) {
    try {
      await release()
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Maintenance cleanup failed')
}

/** Own the clock and partial native view before mounting the real component. */
export async function withMaintenance(
  vi: typeof Vitest.vi,
  mount: MountMaintenance,
  status: ProtectedModeStatus,
  adminSurface: boolean,
  run: (
    view: MaintenanceView,
    feed: ReturnType<typeof maintenanceFeed>,
  ) => Promise<void>,
  options: { initialState?: ConnectionState } = {},
): Promise<void> {
  const now = Date.now()
  const feed = maintenanceFeed(status, options.initialState)
  let view: MaintenanceView | undefined
  let ownsClock = false
  let restoreIntervalSpy: (() => void) | undefined
  const intervals = new Set<ReturnType<typeof setInterval>>()
  const errors: unknown[] = []
  try {
    vi.useFakeTimers({ now, toFake: ['Date', 'setInterval', 'clearInterval'] })
    ownsClock = true
    const schedule = globalThis.setInterval
    const intervalSpy = vi.spyOn(globalThis, 'setInterval')
    restoreIntervalSpy = () => intervalSpy.mockRestore()
    intervalSpy.mockImplementation((handler, timeout, ...args) => {
      const interval = schedule(handler, timeout, ...args)
      intervals.add(interval)
      return interval
    })
    view = await mount(
      { status, connection: feed.connection, adminSurface },
      (partial) => {
        view = partial
      },
    )
    await run(view, feed)
  } catch (error) {
    errors.push(error)
  } finally {
    try {
      await releaseMaintenance([() => view?.unmount(), () => feed.dispose()])
    } catch (error) {
      errors.push(error)
    }
    try {
      await releaseMaintenance([
        () => {
          // Emergency cleanup only after failure, including a partial setup.
          // Successful cases rely on the component's own clock disposal.
          if (errors.length) {
            for (const interval of intervals) clearInterval(interval)
          }
        },
        () => restoreIntervalSpy?.(),
        () => {
          if (ownsClock) vi.useRealTimers()
        },
      ])
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Maintenance case and cleanup failed')
}
