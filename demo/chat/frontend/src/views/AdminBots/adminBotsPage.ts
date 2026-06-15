// The bots admin page selectors: the page-scoped bots table rows fed into a
// headless TableController, each raw row resolved into its BotRow view-model. The
// backend bots table carries the bot entity in the `bots` slot (resolved through
// the Bots collection, so an edit fans out for free) and the runtime agent status
// in the inline `botAgentStatuses` slot. The view reads the controller, never a
// raw store.
import { TableController, type EntityRef, type TableRow } from '@hilos/core'

import { scopes } from '../../bootstrap/session'
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
    presence: status?.[STATUS_FIELD] === STATUS_JOINED ? 'online' : 'offline',
  }
}

/**
 * The headless controller for the bots admin table (client viewport): search by
 * name or description, sort by name / presence / active. Rows resolve through
 * {@link resolveBotRow}.
 */
export const botsTable = new TableController<BotRow>({
  source: scopes.pageTableSignal(BOTS_TABLE),
  resolve: resolveBotRow,
  searchText: (row) => `${row.name} ${row.description ?? ''}`,
  sortValue: (row, field) => {
    switch (field) {
      case 'name':
        return row.name
      case 'presence':
        return row.presence
      case 'active':
        return row.active ? 1 : 0
      default:
        return row.id
    }
  },
  initialSort: { field: 'name', direction: 'asc' },
})
