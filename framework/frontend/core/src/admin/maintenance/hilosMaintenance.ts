// The framework Hilos maintenance section headless: the protected-mode state and
// verifier circle view-models, their live handles, and the section's words that the
// per-framework HilosMaintenancePage views render from. It is framework-agnostic
// (imports no UI framework) and reads only @hilos/core primitives, so the
// Vue/React/Angular sections stay thin (multiframework-core.md).
//
// The section has no on-switch: the freeze under it is core, so a project mounts it
// by registering the page and binding the circle table to it. Its state arrives in
// the page's own signal; the verifier circle - who will check the system after a freeze - arrives through
// the page-scoped `hilosVerifierCircle` viewport table. The section names a verifier
// and takes one out as tracked actions (HIL-1120, HIL-1121): the named row arrives
// over the live table and the removed one leaves it the same way, and the dialog
// confirming a removal holds its row in focus. The online mark on the rows is live:
// a named person opening or closing a tab re-draws that person's row.
// A project supplies a HilosMaintenanceContext - its scope stores, its live
// connection and its action lifecycle - and the framework owns the rest.

import {
  MAINTENANCE_STATE_SIGNAL,
  type HilosMaintenanceState,
} from '../../protocol/maintenanceSignals.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { z } from 'zod'
import { elapsedSecondsSince } from '../backup/hilosBackups.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readHideableString,
  readString,
} from '../../state/fieldReaders.js'
import { type Hideable } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { createSignal, type ReadonlySignal } from '../../state/signal.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

export {
  MAINTENANCE_STATE_SIGNAL,
  maintenanceStateSchema,
  type HilosMaintenanceState,
  MAINTENANCE_STATE_SIGNAL_SCHEMAS,
} from '../../protocol/maintenanceSignals.js'

// Wire keys: the framework verifier circle table and its single inline slot, named
// after the circle's DB source. A project binds its backend to these keys.
const HILOS_MAINTENANCE_CIRCLE_TABLE = 'hilosVerifierCircle'
const HILOS_MAINTENANCE_CIRCLE_SLOT = 'verifierCircle'

/** The section state handle; null means its first frame has not arrived. */
export interface HilosMaintenanceStateHandle {
  readonly state: ReadonlySignal<HilosMaintenanceState | null>
  start(): void
  dispose(): void
}

/**
 * Listen to the page's own state frame and every subsequent server push.
 *
 * @param context The section's project context.
 */
export function createHilosMaintenanceState(
  context: HilosMaintenanceContext,
): HilosMaintenanceStateHandle {
  const state = createSignal<HilosMaintenanceState | null>(null)
  const teardown: Array<() => void> = []

  return {
    state,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === MAINTENANCE_STATE_SIGNAL) {
            // The connection validated this body against maintenanceStateSchema.
            state.set(signal.data as HilosMaintenanceState)
          }
        }),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/** Words of the protected-mode block, shared by every view of this section. */
export const HILOS_MAINTENANCE_STATE_COPY = {
  openTitle: 'The node is open',
  openDescription:
    'No freeze: every page is served and the agents are running.',
  manualTitle: 'Closed to visitors by hand',
  operationTitle: 'The node is frozen',
  phase: 'Phase',
  closedSince: 'Closed since',
  turnedOn: 'Turned on',
  codesInHand: 'Codes in hand',
  circle: 'Circle',
  operation: 'Operation',
  started: 'Started',
  operationPrefix: 'operation',
  manualPhaseNote: 'visitors see the maintenance screen, agents are running',
  verifyingPhaseNote: 'the verification window is open',
  turnedOnFromBrowser: 'with the button in this section',
  turnedOnFromConsole: 'from the console · maintenance:enable',
  circleNamed: 'named',
  circleAdmitted: 'let in',
  minuteAgo: 'minute ago',
  minutesAgo: 'minutes ago',
  justNow: 'just now',
} as const

/** The protected-mode block's three positions; null leaves its room empty. */
export type HilosMaintenancePosition = 'open' | 'manual' | 'operation' | null

/**
 * Choose the block solely from the server's row verdict.
 *
 * @param state The newest section frame, or null before it arrives.
 */
export function hilosMaintenancePosition(
  state: HilosMaintenanceState | null,
): HilosMaintenancePosition {
  if (state === null) {
    return null
  }
  if (state.phase === 'inactive') {
    return 'open'
  }

  return state.manual ? 'manual' : 'operation'
}

/**
 * Text below the manual window's timestamp; the browser's clock moves it locally.
 *
 * @param startedAt Freeze start in ISO-8601, or null if absent.
 * @param nowMs Current browser time in epoch milliseconds.
 */
export function hilosMaintenanceElapsed(
  startedAt: string | null,
  nowMs: number,
): string {
  const elapsed = elapsedSecondsSince(startedAt, nowMs)
  if (elapsed === null || elapsed < 60) {
    return HILOS_MAINTENANCE_STATE_COPY.justNow
  }
  const minutes = Math.floor(elapsed / 60)

  return `${minutes} ${minutes === 1 ? HILOS_MAINTENANCE_STATE_COPY.minuteAgo : HILOS_MAINTENANCE_STATE_COPY.minutesAgo}`
}

/** The lines of an active protected-mode card. */
export interface HilosMaintenanceCard {
  readonly position: 'manual' | 'operation'
  readonly title: string
  readonly phase: string
  readonly phaseNote: string | null
  readonly operation: string | null
  readonly operationCaption: string
  readonly startedAt: string | null
  readonly elapsed: string | null
  readonly turnedOn: string | null
  readonly passCount: number
  readonly circle: string
}

/**
 * Select every active card line without coupling the view to the wire shape.
 *
 * @param state The newest section frame, or null before it arrives.
 * @param nowMs Current browser time in epoch milliseconds.
 */
export function hilosMaintenanceCard(
  state: HilosMaintenanceState | null,
  nowMs: number,
): HilosMaintenanceCard | null {
  const position = hilosMaintenancePosition(state)
  if (state === null || position === null || position === 'open') {
    return null
  }

  return {
    position,
    title:
      position === 'manual'
        ? HILOS_MAINTENANCE_STATE_COPY.manualTitle
        : HILOS_MAINTENANCE_STATE_COPY.operationTitle,
    phase: state.phase,
    phaseNote:
      position === 'manual'
        ? HILOS_MAINTENANCE_STATE_COPY.manualPhaseNote
        : state.phase === 'verifying'
          ? HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote
          : null,
    operation: state.operation,
    operationCaption: `${HILOS_MAINTENANCE_STATE_COPY.operationPrefix} ${state.operation ?? '—'}`,
    startedAt: state.startedAt,
    elapsed:
      position === 'manual'
        ? hilosMaintenanceElapsed(state.startedAt, nowMs)
        : null,
    turnedOn:
      position === 'manual'
        ? state.enteredFromBrowser
          ? HILOS_MAINTENANCE_STATE_COPY.turnedOnFromBrowser
          : HILOS_MAINTENANCE_STATE_COPY.turnedOnFromConsole
        : null,
    passCount: state.passCount,
    circle: `${state.circleNamed} ${HILOS_MAINTENANCE_STATE_COPY.circleNamed} · ${state.circleAdmitted} ${HILOS_MAINTENANCE_STATE_COPY.circleAdmitted}`,
  }
}

/** Tracked browser action; the server supplies identity and entry options. */
export const MAINTENANCE_ENABLE_ACTION = 'maintenance_enable'

/** Tracked browser action; the server resolves the acting browser and manual window. */
export const MAINTENANCE_DISABLE_ACTION = 'maintenance_disable'

/** Tracked browser action returning a code only to its originating submit. */
export const MAINTENANCE_PASS_ACTION = 'maintenance_pass'

/** A code is opaque: validate presence without inventing a format. */
export const maintenancePassReplySchema = z.object({ pass: z.string().min(1) })

/** The code returned once, kept only by the issuing dialog. */
export type HilosMaintenancePassReply = z.infer<
  typeof maintenancePassReplySchema
>

/** Words of code issuance and its single display, shared by every view. */
export const HILOS_MAINTENANCE_PASS_COPY = {
  issue: 'Issue code',
  mintTitle: 'Mint a verification code',
  mint: 'Mint',
  cancel: 'Cancel',
  lead: 'The code is shown once and cannot be retrieved again. Only its SHA-256 hash remains on the node.',
  reuseNote:
    'One code can admit several browsers while this maintenance window is open. Codes stop working when maintenance is turned off or the daemon restarts.',
  codeTitle: 'Verification code',
  resultLead:
    'Give this code to the person who will check the system. This screen will not show it again.',
  resultNote:
    'Enter the code on the maintenance screen at an administrative address.',
  close: 'Close',
  refusalTitle: "Couldn't issue a code",
  unconfirmedNote:
    'The result of issuing a code is unconfirmed. A code may have been issued, but it cannot be recovered. Issue a new code if needed; turn maintenance off and on to invalidate all issued codes.',
} as const

/** Words of the manual-exit confirmation, shared by every view. */
export const HILOS_MAINTENANCE_DISABLE_COPY = {
  disableTitle: 'Turn off maintenance',
  confirm: 'Turn off',
  cancel: 'Cancel',
  lead: 'The system will open to everyone. Every open page will reload itself — the maintenance screen, the pages already inside, and this page too.',
  footer:
    'Issued codes will no longer let anyone in. To close the system again, turn maintenance on again.',
  refusalTitle: "Couldn't turn off maintenance",
  unconfirmedNote:
    'The result of turning off maintenance is unconfirmed. Check the state in Maintenance before trying again.',
} as const

/** Words of the manual-entry confirmation, shared by every view. */
export const HILOS_MAINTENANCE_ENABLE_COPY = {
  enableTitle: 'Turn on maintenance',
  confirm: 'Turn on',
  cancel: 'Cancel',
  lead: 'The system will close to visitors. Every page outside will show the maintenance screen with a fixed message.',
  outside: 'Outside',
  outsideNote: 'Anyone who has not been let in sees the maintenance screen.',
  inside: 'Inside',
  insideNote:
    'You in this tab, the verifier circle members currently in the system, and anyone who enters with a code later.',
  agents: 'Agents',
  agentsNote: 'Keep working while the system is closed to visitors.',
  footer:
    'Any admitted administrator can turn maintenance off in this section.',
  refusalTitle: "Couldn't turn on maintenance",
  unconfirmedNote:
    'The result of turning on maintenance is unconfirmed. Check the state in Maintenance before trying again.',
} as const

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
 * viewport over, and the action lifecycle the section's tracked actions dispatch over.
 * Everything else (the table key, slot name, view-model, and words) is the framework's;
 * the circle is produced on its backend.
 */
export interface HilosMaintenanceContext {
  /** The connection the table sends its viewport over and receives its window / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the section's tracked actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The manual entry/exit and verifier circle actions a maintenance view binds to. */
export interface HilosMaintenanceActions {
  /** Close to visitors, awaiting the server's ready outcome without retrying. */
  sendMaintenanceEnable(): ActionHandle
  /** Open to everyone, awaiting the server's inactive outcome without retrying. */
  sendMaintenanceDisable(): ActionHandle
  /** Issue a code, awaiting its confirmed hash without retrying or retaining the reply. */
  sendMaintenancePass(): ActionHandle<HilosMaintenancePassReply>
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
  /**
   * The address the person was named by, or `HIDDEN_VALUE` for a viewer of the
   * admin view mode, who is sent it hidden.
   */
  readonly identifier: Hideable<string>
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
  /**
   * What an empty circle means, drawn by the table's own empty state: the
   * headline, and the line under it about what happens after an operation.
   */
  empty: {
    title: 'The circle is empty',
    hint: 'After an operation only somebody you hand a code to can check the system.',
  },
  /** Label of the address column. */
  addressColumn: 'Address',
  /** Label of the online mark column. */
  onlineColumn: 'Signed in',
  /** Row mark for a member who holds a live connection. */
  online: 'signed in',
  /** Row mark for a member who holds none. */
  offline: 'not signed in',
  /** Label of the table's main action, which opens the add dialog. */
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

/**
 * The columns of the verifier circle, declared once for every view. The address
 * is the card's title and the signed-in mark its badge; the remove control
 * names the address it confirms, and the membership id stays the row key.
 */
const CIRCLE_COLUMNS: HilosTableColumnOf<HilosMaintenanceCircleRow>[] = [
  {
    key: MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.addressColumn,
    sortable: true,
    card: 'title',
  },
  {
    key: MAINTENANCE_CIRCLE_ONLINE_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.onlineColumn,
    card: 'badge',
  },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    reads: [MAINTENANCE_CIRCLE_IDENTIFIER_FIELD],
  },
]

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
    identifier: readHideableString(slot, MAINTENANCE_CIRCLE_IDENTIFIER_FIELD),
    online: readBoolean(slot, MAINTENANCE_CIRCLE_ONLINE_FIELD),
  }
}

/** What the view lends the circle table: the press of its declared main action. */
export interface HilosMaintenanceCircleTableView {
  /** Open the page's add dialog — what the table's main action does. */
  readonly openAdd: () => void
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
 * What the circle table declares about its frame: the block's title, the two
 * sentences that used to stand under it, one main action, and no search or
 * filters. The main action opens the page's add dialog; the dialog belongs to
 * the view, and the frame only lends it the press.
 *
 * @param view What the view lends: the press that opens its add dialog.
 * @returns The frame declaration.
 */
function circleFrame(view: HilosMaintenanceCircleTableView): HilosTableFrame {
  return {
    title: HILOS_MAINTENANCE_CIRCLE_COPY.title,
    subtitle: `${HILOS_MAINTENANCE_CIRCLE_COPY.rule} ${HILOS_MAINTENANCE_CIRCLE_COPY.volatile}`,
    columns: CIRCLE_COLUMNS,
    mainAction: {
      label: HILOS_MAINTENANCE_CIRCLE_COPY.addButton,
      press: view.openAdd,
    },
    empty: HILOS_MAINTENANCE_CIRCLE_COPY.empty,
  }
}

/**
 * The server-windowed controller for the verifier circle table on the maintenance page.
 * Rows resolve through {@link resolveHilosMaintenanceCircleRow}; the backend orders the
 * first window by address, so the list reads the way the administrator typed it rather
 * than by the order memberships happened to be made. The table hands a row into focus to
 * the dialog confirming its removal, so the dialog sees the row leave in another tab.
 * The first window of a cold entry tells the server which fields the columns draw.
 *
 * @param context The project context (connection and scope stores).
 * @param view What the view lends the table: the press of its main action.
 */
export function createHilosMaintenanceCircleTable(
  context: HilosMaintenanceContext,
  view: HilosMaintenanceCircleTableView,
): HilosMaintenanceCircleTable {
  const controller = new TableViewportController<HilosMaintenanceCircleRow>({
    resolve: resolveHilosMaintenanceCircleRow,
    frame: circleFrame(view),
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.MAINTENANCE,
        HILOS_MAINTENANCE_CIRCLE_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.MAINTENANCE,
        HILOS_MAINTENANCE_CIRCLE_TABLE,
        rendered,
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
 * The maintenance mutation surface: manual entry/exit and circle changes submit
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
    sendMaintenanceEnable() {
      return context.actions.dispatch(MAINTENANCE_ENABLE_ACTION, {})
    },
    sendMaintenanceDisable() {
      return context.actions.dispatch(MAINTENANCE_DISABLE_ACTION, {})
    },
    sendMaintenancePass() {
      return context.actions.dispatch(
        MAINTENANCE_PASS_ACTION,
        {},
        {
          replySchema: maintenancePassReplySchema,
        },
      )
    },
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
