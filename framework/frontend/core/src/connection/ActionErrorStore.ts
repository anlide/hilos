// The framework-level action-error store. `action_error` is a framework signal
// (PHP `SignalConstants::ACTION_ERROR`, sent by AbstractPage when an action — or
// an async agent result routed back to it — fails), so the core, not each
// project, owns turning it into reactive state: it subscribes to a connection's
// `actionError` events and keeps the latest reason per action name. A view reads
// `signal(action)` and the action's caller clears it on a fresh submit. The
// request-correlated acknowledgement lifecycle (requestId, success acks, toasts)
// layers on top at step 7.4; this is the error half consumers need now.

import {
  createSignal,
  type ReadonlySignal,
  type Unsubscribe,
  type WritableSignal,
} from '../state/signal.js'

/** The slice of HilosConnection the store subscribes to. */
export interface ActionErrorSource {
  on(
    event: 'actionError',
    listener: (signal: { action: string; reason: string }) => void,
  ): Unsubscribe
}

export class ActionErrorStore {
  /** Cells are created lazily, so an action can be watched before its first error. */
  private readonly cells = new Map<string, WritableSignal<string | null>>()

  /**
   * Record every action failure the connection reports.
   *
   * @param source The connection (or a test double) emitting `actionError`.
   */
  constructor(source: ActionErrorSource) {
    source.on('actionError', ({ action, reason }) => {
      this.cell(action).set(reason)
    })
  }

  /**
   * The latest failure reason for an action, or null when it is clear or has
   * never failed.
   *
   * @param action The action name to watch.
   */
  signal(action: string): ReadonlySignal<string | null> {
    return this.cell(action)
  }

  /**
   * Clear an action's error — the caller does this when re-firing the action.
   *
   * @param action The action name to clear.
   */
  clear(action: string): void {
    this.cell(action).set(null)
  }

  private cell(action: string): WritableSignal<string | null> {
    let cell = this.cells.get(action)
    if (!cell) {
      cell = createSignal<string | null>(null)
      this.cells.set(action, cell)
    }

    return cell
  }
}
