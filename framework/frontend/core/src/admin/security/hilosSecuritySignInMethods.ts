// The framework Hilos sign-in methods admin headless (HIL-427): the methods
// view-model, its row resolver, and the table / actions factories the
// per-framework HilosSecuritySignInMethodsPage views render from. Framework-
// agnostic (imports no UI framework), so the three views stay thin
// (multiframework-core.md).
//
// The rows come from the project's method directory on the backend: one per
// method the project wired, with its name, whether the installation can serve it
// and, for a provider, the key its own screen is reached by. Whether a method is
// ON is not read from the row, though. One setting switches every method, and a
// change of it moves any number of rows while the table answers a change with one
// row; so the switch reads the live enabled set every connection is sent
// ({@link sessionAuthMethods}) — the same set the sign-in surface is drawn from —
// and redraws when that set arrives, whichever door changed it.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { sessionAuthMethods } from '../../session/sessionScope.js'
import {
  readBoolean,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** One row of the sign-in methods table — the framework methods view-model. */
export interface HilosSignInMethodRow {
  /** The method key; also the table row key, e.g. `password` or `oauth:github`. */
  readonly methodKey: string
  /** The name the screen shows the method under; the key when the row names none. */
  readonly label: string
  /** Whether the method was on when the window was drawn; the switch reads the live set instead. */
  readonly enabled: boolean
  /** Whether the installation can serve the method: a provider configured, a code deliverable. */
  readonly ready: boolean
  /** The provider key for a provider method, whose own screen it links to; null otherwise. */
  readonly providerKey: string | null
}

// Wire keys: the framework sign-in methods table, its inline row slot, and the
// switch action. A project binds its backend to these (Hilos::TABLES / PAGE_TABLES).
const METHODS_TABLE = 'hilosSecuritySignInMethods'
const METHODS_SLOT = 'method'
const METHOD_SET_ACTION = 'security_sign_in_method_set'

/**
 * Payload keys of the sign-in methods row slot (mirrors the backend row shape).
 * Exported because the view declares its columns from the same keys.
 */
export const HilosSignInMethodRowKey = {
  methodKey: 'methodKey',
  label: 'label',
  enabled: 'enabled',
  ready: 'ready',
  providerKey: 'providerKey',
} as const

/**
 * The project-supplied context the sign-in methods admin reads from: the
 * scope-partitioned stores that own the page-scoped table and the session scope
 * the live set lives in, the connection the table window rides, and the action
 * lifecycle the switch dispatches over.
 */
export interface HilosSignInMethodsContext {
  /** The connection the table sends its viewport over and receives windows / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope of the table and the session scope of the set. */
  readonly scopes: ScopeManager
  /** The action lifecycle the tracked switch dispatches over. */
  readonly actions: ActionLifecycle
}

/** The switch a sign-in methods view binds to. */
export interface HilosSignInMethodsActions {
  /**
   * Switch one method on or off, as a tracked action. The backend refuses the
   * switch that would leave no method on, on the action's `::fail`; the switch
   * redraws when the new set arrives.
   *
   * @param methodKey The method to switch.
   * @param enabled Whether it should be on.
   */
  sendMethodSet(methodKey: string, enabled: boolean): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw sign-in methods row into its view-model. The projected method
 * rides a single inline `method` slot, keyed by the method key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosSignInMethodRow(
  row: TableRow,
): HilosSignInMethodRow {
  const slot = recordSlot(row.slots[METHODS_SLOT]) ?? {}
  const methodKey =
    readString(slot, HilosSignInMethodRowKey.methodKey) || String(row.rowKey)

  return {
    methodKey,
    // An unnamed method is shown by its key: a real name a reader can act on.
    label: readStringOrNull(slot, HilosSignInMethodRowKey.label) ?? methodKey,
    enabled: readBoolean(slot, HilosSignInMethodRowKey.enabled),
    ready: readBoolean(slot, HilosSignInMethodRowKey.ready),
    providerKey: readStringOrNull(slot, HilosSignInMethodRowKey.providerKey),
  }
}

/** The sign-in methods table handle a view drives: the controller, the live set, and the lifecycle. */
export interface HilosSignInMethodsTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosSignInMethodRow>
  /** The keys of the methods switched on now, live from the session scope. */
  readonly enabledKeys: ReadonlySignal<readonly string[]>
  /** Bind the table to the connection — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/** The columns of the sign-in methods table, in display order. */
const METHODS_COLUMNS: HilosTableColumnOf<HilosSignInMethodRow>[] = [
  {
    key: HilosSignInMethodRowKey.methodKey,
    label: 'Method',
    reads: [HilosSignInMethodRowKey.label],
  },
  {
    key: HilosSignInMethodRowKey.enabled,
    label: 'Enabled',
    // The switch is named after the method's label.
    reads: [HilosSignInMethodRowKey.label],
  },
  {
    key: HilosSignInMethodRowKey.ready,
    label: 'Status',
    // A provider's status links to its own screen.
    reads: [HilosSignInMethodRowKey.providerKey],
  },
]

/**
 * What the sign-in methods table declares about its frame. No search: a project
 * wires a handful of methods. No title: the page heading already names it.
 */
const METHODS_FRAME: HilosTableFrame = {
  columns: METHODS_COLUMNS,
  empty: { title: 'No sign-in methods wired.' },
}

/**
 * The server-windowed controller for the sign-in methods table, plus the live
 * enabled set its switches read. Rows resolve through
 * {@link resolveHilosSignInMethodRow}.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosSignInMethodsTable(
  context: HilosSignInMethodsContext,
): HilosSignInMethodsTable {
  const controller = new TableViewportController<HilosSignInMethodRow>({
    resolve: resolveHilosSignInMethodRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.SECURITY_SIGN_IN_METHODS,
        METHODS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.SECURITY_SIGN_IN_METHODS,
        METHODS_TABLE,
        rendered,
      ),
    frame: METHODS_FRAME,
  })
  const methods = sessionAuthMethods(context.scopes)
  let teardown: Array<() => void> = []

  return {
    controller,
    enabledKeys: computedSignal(() => methods.get().map((entry) => entry.key)),
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.SECURITY_SIGN_IN_METHODS,
            tableKey: METHODS_TABLE,
          },
          controller,
        ),
      ]
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The sign-in methods switch: a tracked action over the lifecycle. The handle's
 * `done` resolves on the backend's `::success` and rejects on `::fail` — the view
 * toasts the refusal and puts the switch back.
 *
 * @param context The project context (the action lifecycle the switch dispatches over).
 */
export function createHilosSignInMethodsActions(
  context: HilosSignInMethodsContext,
): HilosSignInMethodsActions {
  return {
    sendMethodSet(methodKey, enabled) {
      return context.actions.dispatch(METHOD_SET_ACTION, { methodKey, enabled })
    },
  }
}
