// The framework Hilos maintenance section headless (HIL-1119): the verifier circle
// view-model, its row resolver, the table factory, and the section's words that the
// per-framework HilosMaintenancePage views render from. It is framework-agnostic
// (imports no UI framework) and reads only @hilos/core primitives, so the
// Vue/React/Angular sections stay thin (multiframework-core.md).
//
// The section has no on-switch: the freeze under it is core, so a project mounts it
// by registering the page and binding the circle table to it. Its one block today is
// the verifier circle - who will check the system after a freeze - delivered through
// the page-scoped `hilosVerifierCircle` viewport table. The section names a verifier
// and takes one out as tracked actions (HIL-1120, HIL-1121): the named row arrives
// over the live table and the removed one leaves it the same way, and the dialog
// confirming a removal holds its row in focus. The online mark on the rows is live:
// a named person opening or closing a tab re-draws that person's row.
// A project supplies a HilosMaintenanceContext - its scope stores, its live
// connection and its action lifecycle - and the framework owns the rest.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readBoolean, readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'

// Wire keys: the framework verifier circle table and its single inline slot, named
// after the circle's DB source. A project binds its backend to these keys.
const HILOS_MAINTENANCE_CIRCLE_TABLE = 'hilosVerifierCircle'
const HILOS_MAINTENANCE_CIRCLE_SLOT = 'verifierCircle'
// Wire action names: byte-equal to the backend HilosSignalConstants::MAINTENANCE_CIRCLE_ADD
// and HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE.
const MAINTENANCE_CIRCLE_ADD_ACTION = 'maintenance_circle_add'
const MAINTENANCE_CIRCLE_REMOVE_ACTION = 'maintenance_circle_remove'

// Row payload keys of the verifier circle slot. The membership id is not among them
// and is not missing either: it is the row key, and it stays off the slot because a
// slot carrying a non-null `id` is read as an entity fragment and replaced by a
// reference.

/** Row payload key of the identity type the member was named under. */
const MAINTENANCE_CIRCLE_IDENTITY_TYPE_FIELD = 'identityType'

/** Row payload key of the address the member was named by (also the default sort field). */
export const MAINTENANCE_CIRCLE_IDENTIFIER_FIELD = 'identifier'

/** Row payload key of whether that person holds a live connection right now. */
export const MAINTENANCE_CIRCLE_ONLINE_FIELD = 'online'

/**
 * The project-supplied context the maintenance section reads from: the scope-partitioned
 * stores that own the page-scoped circle table, the live connection the table sends its
 * viewport over, and the action lifecycle the circle's tracked actions dispatch over.
 * Everything else (the table key, slot name, view-model, and words) is the framework's;
 * the circle is produced on its backend.
 */
export interface HilosMaintenanceContext {
  /** The connection the table sends its viewport over and receives its window / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the circle's tracked actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The verifier circle mutation surface a maintenance view binds to. */
export interface HilosMaintenanceActions {
  /**
   * Name one more person to the verifier circle, as a tracked action. The address
   * travels as the operator typed it: which identity proves it, and the form it is
   * stored in, are the server's answer. A refusal - an unproven address, one already
   * in the circle, a second address of a person already named - comes back with the
   * ack; on success the ack carries the sentence the toast shows and the row arrives
   * over the live table.
   *
   * @param identifier The email or phone number the person is named by, as typed.
   */
  sendMaintenanceCircleAdd(identifier: string): ActionHandle
  /**
   * Take one person out of the verifier circle, as a tracked action. The membership
   * goes by the row key the table handed out, not by the address shown beside it. A
   * key that names no row any more is refused in the ack; on success the ack carries
   * the sentence the toast shows and the row leaves over the live table.
   *
   * @param memberId The membership id — the row key of the circle table.
   */
  sendMaintenanceCircleRemove(memberId: number): ActionHandle
}

/** One row of the verifier circle table — a person named to check the system after a freeze. */
export interface HilosMaintenanceCircleRow {
  /** The membership id; also the table row key. */
  readonly memberId: number
  /** The identity type the person was named under. */
  readonly identityType: string
  /** The address the person was named by. */
  readonly identifier: string
  /** Whether that person holds a live connection right now. */
  readonly online: boolean
}

/**
 * The words of the verifier circle block, shared by the three views so they cannot
 * drift between them — the column labels included.
 */
export const HILOS_MAINTENANCE_CIRCLE_COPY = {
  /** Heading of the block, and the table's label. */
  title: 'Who will check the system',
  /** How a named person actually gets in, so nobody waits for an email. */
  rule:
    'They get in with the tab they already have open: only members signed in at the moment ' +
    'the system is frozen are let into the verification window.',
  /** The one property of the list that surprises people afterwards. */
  volatile:
    'The circle itself does not survive a restore - afterwards the one stored in the archive applies.',
  /** What an empty circle means, in the terms of what happens after an operation. */
  empty:
    'The circle is empty - after an operation only somebody you hand a code to can check the system.',
  /** Label of the address column. */
  addressColumn: 'Address',
  /** Label of the online mark column. */
  onlineColumn: 'Signed in',
  /** Row mark for a member who holds a live connection. */
  online: 'signed in',
  /** Row mark for a member who holds none. */
  offline: 'not signed in',
  /** Label of the button in the block's header that opens the add dialog. */
  addButton: 'Add a verifier',
  /** Title of the add dialog. */
  addTitle: 'Add a verifier',
  /** What the dialog finds a person by, and why an unproven address will not do. */
  addLead:
    'A person is found by an address they have proven - an email or a phone number. ' +
    'An unproven address will not do: it cannot tell who that is.',
  /** Label of the dialog's one field. */
  addField: 'Address',
  /** Placeholder hint of that field. */
  addPlaceholder: 'ops@example.com',
  /** Label of the dialog's confirm button. */
  addConfirm: 'Add',
  /** Title of the refusal details window of the add dialog. */
  addRefusalTitle: "Couldn't add the verifier",
  /** Title of the remove dialog, and the title and label of the row's remove button. */
  removeTitle: 'Remove from the circle',
  /** Words of the remove question before the address. */
  removeAskBefore: 'Remove',
  /** Words of the remove question after the address. */
  removeAskAfter: 'from the verifier circle?',
  /** Why a removal changes nothing about an operation already under way. */
  removeNote:
    'An operation already under way is not affected: the circle is photographed the moment ' +
    'the system is frozen, and a change after that lets nobody in and shuts nobody out.',
  /** Label of the remove dialog's confirm button. */
  removeConfirm: 'Remove',
  /** Notice of the remove dialog when its row was taken out in another tab. */
  removeGone: 'Removed from the circle elsewhere.',
  /** Label of the remove dialog's confirm button once its row is gone. */
  removeGoneConfirm: 'Removed',
  /** Title of the refusal details window of the remove dialog. */
  removeRefusalTitle: "Couldn't remove the verifier",
} as const

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw verifier circle table row into its view-model. The three fields ride a
 * single inline `verifierCircle` slot, the online mark included: it is computed when the
 * row is built rather than stored, so it arrives beside the two that are.
 *
 * The membership id comes off the row key rather than out of the slot, because a slot
 * carrying a non-null `id` is read as an entity fragment by the normalizer and replaced
 * with a reference. It is the same value either way — the backend keys the row by it.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosMaintenanceCircleRow(
  row: TableRow,
): HilosMaintenanceCircleRow {
  const slot = recordSlot(row.slots[HILOS_MAINTENANCE_CIRCLE_SLOT]) ?? {}

  return {
    memberId: Number(row.rowKey),
    identityType: readString(slot, MAINTENANCE_CIRCLE_IDENTITY_TYPE_FIELD),
    identifier: readString(slot, MAINTENANCE_CIRCLE_IDENTIFIER_FIELD),
    online: readBoolean(slot, MAINTENANCE_CIRCLE_ONLINE_FIELD),
  }
}

/** The circle table handle a maintenance view drives: the controller plus its mount lifecycle. */
export interface HilosMaintenanceCircleTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosMaintenanceCircleRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the verifier circle table on the maintenance page.
 * Rows resolve through {@link resolveHilosMaintenanceCircleRow}; the backend orders the
 * first window by address, so the list reads the way the administrator typed it rather
 * than by the order memberships happened to be made. The table hands a row into focus to
 * the dialog confirming its removal, so the dialog sees the row leave in another tab.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosMaintenanceCircleTable(
  context: HilosMaintenanceContext,
): HilosMaintenanceCircleTable {
  const controller = new TableViewportController<HilosMaintenanceCircleRow>({
    resolve: resolveHilosMaintenanceCircleRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.MAINTENANCE,
        HILOS_MAINTENANCE_CIRCLE_TABLE,
        descriptor,
      ),
    sendFocus: (rowKey) =>
      context.connection.sendTableRowFocus(
        HilosPages.MAINTENANCE,
        HILOS_MAINTENANCE_CIRCLE_TABLE,
        rowKey,
      ),
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.MAINTENANCE,
            tableKey: HILOS_MAINTENANCE_CIRCLE_TABLE,
          },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The verifier circle mutation surface: naming a person and taking one out each submit
 * as a tracked action over the lifecycle, returning an ActionHandle whose `done`
 * resolves on the backend's success ack and rejects on its refusal. Each action is
 * answered in its own ack, so no listener for addressed failures is set here, and the
 * toast is the driver's.
 *
 * @param context The project context (the action lifecycle the action dispatches over).
 */
export function createHilosMaintenanceActions(
  context: HilosMaintenanceContext,
): HilosMaintenanceActions {
  return {
    sendMaintenanceCircleAdd(identifier) {
      return context.actions.dispatch(MAINTENANCE_CIRCLE_ADD_ACTION, {
        identifier,
      })
    },
    sendMaintenanceCircleRemove(memberId) {
      return context.actions.dispatch(MAINTENANCE_CIRCLE_REMOVE_ACTION, {
        memberId,
      })
    },
  }
}
