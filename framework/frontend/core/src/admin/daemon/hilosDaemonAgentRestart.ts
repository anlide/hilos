import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import {
  type HilosDaemonAgentRow,
  type HilosDaemonAgentsContext,
} from './hilosDaemonAgents.js'
import { readHilosDaemonNodeHeading } from './hilosDaemonNode.js'

/** Tracked page action requesting one agent restart from the master. */
export const HILOS_DAEMON_AGENT_RESTART_ACTION = 'hilos_daemon_agent_restart'

/** Confirmation over the current focused row; the picture owns all restart marks. */
export interface HilosDaemonAgentRestart {
  readonly opened: ReadonlySignal<boolean>
  readonly target: ReadonlySignal<HilosDaemonAgentRow | undefined>
  readonly canSubmit: ReadonlySignal<boolean>
  /**
   * Whether the table cannot vouch for any restart now: the node heading is
   * missing or silent, the window is loading or unavailable, or it is frozen.
   */
  readonly unavailable: ReadonlySignal<boolean>
  /**
   * Why a row's restart is off, in the server's words: the delivered
   * `restartUnavailableReason` while the table is {@link unavailable}, else the
   * row's current `restartDisabledReason`. Null when the server refuses nothing;
   * a restart in flight from this page gives no reason of its own.
   *
   * @param row The row whose restart opener asks.
   */
  reasonFor(row: HilosDaemonAgentRow): string | null
  open(rowKey: string): void
  close(): void
  sendRestart(): ActionHandle
}

/**
 * Follow the selected row and dispatch only after the reader confirms its restart.
 *
 * @param context Page scopes and tracked action lifecycle.
 * @param controller The agents table holding the row in focus.
 * @param nodeId The node named by the current page address.
 */
export function createHilosDaemonAgentRestart(
  context: HilosDaemonAgentsContext,
  controller: TableViewportController<HilosDaemonAgentRow>,
  nodeId: string,
): HilosDaemonAgentRestart {
  const key = createSignal<string | null>(null)
  const pending = createSignal(false)
  const heading = readHilosDaemonNodeHeading(context.scopes)
  const opened = computedSignal(() => key.get() !== null)
  const target = computedSignal(() => {
    const row = controller.focusedRow.get()
    return row?.rowKey === key.get() ? row : undefined
  })
  const unavailable = computedSignal(
    () =>
      heading.get() === null ||
      heading.get()?.state === 'silent' ||
      controller.frame.body.get() === 'loading' ||
      controller.frame.body.get() === 'unavailable' ||
      controller.frozenSince.get() !== null,
  )
  const canSubmit = computedSignal(() => {
    const row = target.get()
    return (
      opened.get() &&
      !pending.get() &&
      row !== undefined &&
      !row.restarting &&
      row.restartDisabledReason === null &&
      !unavailable.get()
    )
  })

  return {
    opened,
    target,
    canSubmit,
    unavailable,
    reasonFor(row) {
      return unavailable.get()
        ? row.restartUnavailableReason
        : row.restartDisabledReason
    },
    open(rowKey) {
      if (pending.get()) return
      const row = controller.focusRow(rowKey)
      if (row === null) return
      key.set(row.rowKey)
    },
    close() {
      if (pending.get()) return
      key.set(null)
      controller.releaseFocus()
    },
    sendRestart() {
      const row = target.get()
      if (!canSubmit.get() || row === undefined) {
        throw new Error('The selected agent is unavailable for restart.')
      }
      const handle = context.actions.dispatch(
        HILOS_DAEMON_AGENT_RESTART_ACTION,
        {
          agentId: row.agentId,
          nodeId,
        },
      )
      pending.set(true)
      void handle.done.then(
        () => pending.set(false),
        () => pending.set(false),
      )
      return handle
    },
  }
}
