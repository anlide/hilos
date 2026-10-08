// The bots admin page selectors: the page-scoped bots table rows fed into a
// server-windowed TableViewportController, each raw row resolved into its BotRow
// view-model. The
// backend bots table carries the bot entity in the `bots` slot (resolved through
// the Bots collection, so an edit fans out for free) and the runtime agent status
// in the inline `botAgentStatuses` slot. The view reads the controller, never a
// raw store.
import {
  HILOS_TABLE_ACTIONS_KEY,
  TableViewportController,
  bindTableViewport,
  type EntityRef,
  type HilosTableColumnOf,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core'

import { connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'
import { PAGE_ADMIN_BOTS } from '../../pages/keys'
import { Bots } from '../../types'
import { type BotRow } from './types/tables/BotRow'

// Wire keys: the bots table (ChatTableContext::bots) and its row slots — the bot
// entity (ChatDbContext::bots, typed via pageEntityTypes) and the inline runtime
// status (ChatRtContext::botAgentStatuses), whose `status` is `joined` when online.
const BOTS_TABLE = 'bots'
const BOT_SLOT = 'bots'
const STATUS_SLOT = 'botAgentStatuses'
const STATUS_FIELD = 'status'
const STATUS_JOINED = 'joined'
/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw bots-table row into its view-model: the referenced bot entity
 * folded together with its inline runtime status. Reading the bot through the
 * collection keeps the resolved row reactive to an edit.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveBotRow(row: TableRow): BotRow {
  const ref = row.slots[BOT_SLOT] as EntityRef | undefined
  const bot = ref ? Bots.signal(ref).get() : undefined
  const status = recordSlot(row.slots[STATUS_SLOT])

  // Optional strings normalize empty to null, matching the form's `'' → null`, so
  // an untouched field never reads as dirty or unequal to what was submitted.
  return {
    id: Number(bot?.id ?? row.rowKey),
    name: bot?.name ?? '',
    description: bot?.description || null,
    style: bot?.style || null,
    topics: bot?.topics || null,
    personality: bot?.personality || null,
    active: bot?.active ?? false,
    status: status?.[STATUS_FIELD] === STATUS_JOINED ? 'online' : 'offline',
  }
}

/** What the view lends the bots table: the press of its declared main action. */
export interface BotsTableView {
  /** Open the page's add dialog — what the table's main action does. */
  readonly openAdd: () => void
}

/** The bots table handle the admin bots view drives: controller plus mount lifecycle. */
export interface BotsTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<BotRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

const BOTS_COLUMNS: HilosTableColumnOf<BotRow>[] = [
  { key: 'name', label: 'Name', sortable: true, card: 'title' },
  { key: 'description', label: 'Description' },
  { key: 'status', label: 'Status', sortable: true, card: 'badge' },
  { key: 'active', label: 'Active', sortable: true },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    reads: ['name', 'description', 'style', 'topics', 'personality', 'active'],
  },
]

function botsFrame(view: BotsTableView): HilosTableFrame {
  return {
    search: { placeholder: 'Search bots…' },
    columns: BOTS_COLUMNS,
    mainAction: {
      label: 'Add bot',
      press: view.openAdd,
    },
    empty: { title: 'No bots yet.' },
  }
}

/**
 * The server-windowed controller for the bots admin table: search, sort, and
 * paging change the viewport descriptor sent over the connection, and the backend
 * replies a window plus live deltas scoped to the table's (page, tableKey)
 * address. Rows resolve through {@link resolveBotRow}.
 *
 * @param view What the view lends the table: the press of its main action.
 */
export function createBotsTable(view: BotsTableView): BotsTable {
  const controller = new TableViewportController<BotRow>({
    resolve: resolveBotRow,
    frame: botsFrame(view),
    sendViewport: (descriptor) =>
      connection.sendTableViewport(PAGE_ADMIN_BOTS, BOTS_TABLE, descriptor),
    sendRendered: (rendered) =>
      connection.sendTableRendered(PAGE_ADMIN_BOTS, BOTS_TABLE, rendered),
    sendFocus: (rowKey) =>
      connection.sendTableRowFocus(PAGE_ADMIN_BOTS, BOTS_TABLE, rowKey),
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          connection,
          scopes,
          { page: PAGE_ADMIN_BOTS, tableKey: BOTS_TABLE },
          controller,
          // The bot slot is an entity; normalize it under the collection's type so
          // the row resolves through Bots.
          { entityTypes: { [BOT_SLOT]: Bots.type } },
        ),
      )
    },
    dispose() {
      // The controller outlives the page: a dialog open at unmount would leave its
      // row in focus, and the next mount's first window would say that focus again.
      controller.releaseFocus()
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}
