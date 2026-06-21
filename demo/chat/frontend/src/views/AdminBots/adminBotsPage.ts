// The bots admin page selectors: the page-scoped bots table rows fed into a
// headless TableController, each raw row resolved into its BotRow view-model. The
// backend bots table carries the bot entity in the `bots` slot (resolved through
// the Bots collection, so an edit fans out for free) and the runtime agent status
// in the inline `botAgentStatuses` slot. The view reads the controller, never a
// raw store.
import {
  TableViewportController,
  bindTableViewport,
  type EntityRef,
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
const BOTS_PAGE_SIZE = 10

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
    presence: status?.[STATUS_FIELD] === STATUS_JOINED ? 'online' : 'offline',
  }
}

/**
 * The server-windowed controller for the bots admin table: search, sort, and
 * paging change the viewport descriptor sent over the connection, and the backend
 * replies a window plus live deltas scoped to the table's (page, tableKey)
 * address. Rows resolve through {@link resolveBotRow}.
 */
export const botsTable = new TableViewportController<BotRow>({
  resolve: resolveBotRow,
  sendViewport: (descriptor) =>
    connection.sendTableViewport(PAGE_ADMIN_BOTS, BOTS_TABLE, descriptor),
  pageSize: BOTS_PAGE_SIZE,
  initialSort: { field: 'name', direction: 'asc' },
})

const teardown: Array<() => void> = []

/** Bind the table to the connection and request the first window — call on mount. */
export function startBotsTable(): void {
  teardown.push(
    bindTableViewport(
      connection,
      scopes,
      { page: PAGE_ADMIN_BOTS, tableKey: BOTS_TABLE },
      botsTable,
      // The bot slot is an entity; normalize it under the collection's type so
      // the row resolves through Bots.
      { entityTypes: { [BOT_SLOT]: Bots.type } },
    ),
    // Re-request the window whenever the socket (re)connects: the initial request
    // below can run before the connection is open, and a reconnect is a fresh
    // exchange that no longer remembers this connection's window.
    connection.on('state', (state) => {
      if (state === 'connected') {
        botsTable.start()
      }
    }),
  )
  botsTable.start()
}

/** Unbind from the connection — call on unmount. */
export function disposeBotsTable(): void {
  for (const off of teardown.splice(0)) {
    off()
  }
}
