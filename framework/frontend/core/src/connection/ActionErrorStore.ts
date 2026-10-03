// The framework-level action-error store. `action_error` is a framework signal
// (PHP `SignalConstants::ACTION_ERROR`, sent by AbstractPage when an action — or
// an async agent result routed back to it — fails), so the core, not each
// project, owns turning it into reactive state: it subscribes to a connection's
// `actionError` events and keeps the latest reason per action name. A view reads
// `signal(action)` and the action's caller clears it on a fresh submit. The
// request-correlated acknowledgement lifecycle (requestId, success acks) is the
// ActionLifecycle store; this action-name-keyed store remains the simpler half
// for consumers that only need the latest failure per action. A refusal of the
// admin view mode (code `view_mode`) is kept as the screen's sentence for it,
// not the server's impersonal reason (see admin/viewMode.ts).

import { actionFailureReason } from '../admin/viewMode.js'
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
    listener: (signal: {
      action: string
      reason: string
      errorCode?: string | undefined
    }) => void,
  ): Unsubscribe
}

export class ActionErrorStore {
  /** Cells are created lazily, so an action can be watched before its first error. */
  private readonly cells = new Map<string, WritableSignal<string | null>>()
  private readonly stop: Unsubscribe

  /**
   * Record every action failure the connection reports.
   *
   * @param source The connection (or a test double) emitting `actionError`.
   */
  constructor(source: ActionErrorSource) {
    this.stop = source.on('actionError', ({ action, reason, errorCode }) => {
      this.cell(action).set(actionFailureReason(reason, errorCode))
    })
  }

  /** Release this store's connection listener when its owning view leaves. */
  dispose(): void {
    this.stop()
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
