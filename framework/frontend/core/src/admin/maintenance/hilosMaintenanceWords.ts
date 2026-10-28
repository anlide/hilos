// Maintenance words table: lists stubs for protected-mode operations (HIL-1677).
//
// A read-only server-windowed table over the project's protected-mode stub registry.
// Displays each operation's heading, body message, and banner phrase, or indicates
// that the operation falls back to the default stub when no own entry exists.

import { HilosPages, type HilosPageKey } from '../../routing/hilosPages.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import {
  readBoolean,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosMaintenanceContext } from './hilosMaintenance.js'

/** Canonical table key under which a project registers this table. */
export const HILOS_MAINTENANCE_WORDS_TABLE = 'hilosMaintenanceWords'

/** Wire slot the row payload rides under; matches the backend stub slot. */
export const HILOS_MAINTENANCE_WORDS_SLOT = 'stub'

/** Row payload key of the protected-mode operation name. */
export const MAINTENANCE_WORDS_OPERATION_FIELD = 'operation'

/** Row payload key of the heading on the maintenance surface. */
export const MAINTENANCE_WORDS_TITLE_FIELD = 'title'

/** Row payload key of the message sentence under the heading. */
export const MAINTENANCE_WORDS_MESSAGE_FIELD = 'message'

/** Row payload key of the sentence shown on the banner. */
export const MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD = 'bannerMessage'

/** Row payload key of the fallback flag indicating default stub usage. */
export const MAINTENANCE_WORDS_USES_DEFAULT_FIELD = 'usesDefault'

/** English copy for the maintenance words table frame and fallback row message. */
export const HILOS_MAINTENANCE_WORDS_COPY = {
  title: 'Words used to announce maintenance',
  noOwnEntry: 'No own entry — uses default',
  empty: 'No words registered.',
} as const

/** A row in the maintenance words table representing one operation's announcement copy. */
export interface HilosMaintenanceWordsRow {
  readonly operation: string
  readonly title: string | null
  readonly message: string | null
  readonly bannerMessage: string | null
  readonly usesDefault: boolean
}

/** The words table handle: controller and mount lifecycle. */
export interface HilosMaintenanceWordsTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosMaintenanceWordsRow>
  /** Bind the table to the connection and ingests windows — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The columns of the maintenance words table, declared once for every view.
 * Intrinsic height by default, without sortability, actions, search, or filters.
 */
export const HILOS_MAINTENANCE_WORDS_COLUMNS: readonly HilosTableColumnOf<HilosMaintenanceWordsRow>[] =
  [
    {
      key: MAINTENANCE_WORDS_OPERATION_FIELD,
      label: 'Operation',
      card: 'title',
    },
    {
      key: MAINTENANCE_WORDS_TITLE_FIELD,
      label: 'Title',
      card: 'field',
      reads: [MAINTENANCE_WORDS_USES_DEFAULT_FIELD],
    },
    {
      key: MAINTENANCE_WORDS_MESSAGE_FIELD,
      label: 'Message',
      card: 'field',
    },
    {
      key: MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD,
      label: 'Banner',
      card: 'field',
    },
  ]

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw words table row into its view-model.
 *
 * Operation names the protected-mode operation, while title, message, and bannerMessage
 * provide nullable copy. The boolean usesDefault flag indicates fallback to the default stub.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosMaintenanceWordsRow(
  row: TableRow,
): HilosMaintenanceWordsRow {
  const slot = recordSlot(row.slots[HILOS_MAINTENANCE_WORDS_SLOT]) ?? {}

  return {
    operation:
      readString(slot, MAINTENANCE_WORDS_OPERATION_FIELD) || String(row.rowKey),
    title: readStringOrNull(slot, MAINTENANCE_WORDS_TITLE_FIELD),
    message: readStringOrNull(slot, MAINTENANCE_WORDS_MESSAGE_FIELD),
    bannerMessage: readStringOrNull(
      slot,
      MAINTENANCE_WORDS_BANNER_MESSAGE_FIELD,
    ),
    usesDefault: readBoolean(slot, MAINTENANCE_WORDS_USES_DEFAULT_FIELD),
  }
}

/**
 * What the words table declares about its frame: title, columns, and empty state.
 * Search, filters, main action, and bulk actions are not supported.
 */
function wordsFrame(): HilosTableFrame {
  return {
    title: HILOS_MAINTENANCE_WORDS_COPY.title,
    columns: HILOS_MAINTENANCE_WORDS_COLUMNS,
    empty: {
      title: HILOS_MAINTENANCE_WORDS_COPY.empty,
    },
  }
}

/**
 * The server-windowed controller factory for the maintenance words table.
 * Rows resolve through {@link resolveHilosMaintenanceWordsRow}. The first window is
 * consumed from page_response without sending a separate mount request.
 *
 * @param context The maintenance context (connection and scopes).
 * @param page The page scope the table belongs to; defaults to maintenance words.
 */
export function createHilosMaintenanceWordsTable(
  context: HilosMaintenanceContext,
  page: HilosPageKey = HilosPages.MAINTENANCE_WORDS,
): HilosMaintenanceWordsTable {
  const controller = new TableViewportController<HilosMaintenanceWordsRow>({
    resolve: resolveHilosMaintenanceWordsRow,
    frame: wordsFrame(),
    rowMessage: (row) =>
      row.usesDefault
        ? {
            fromColumn: MAINTENANCE_WORDS_TITLE_FIELD,
            text: HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
          }
        : null,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        page,
        HILOS_MAINTENANCE_WORDS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_MAINTENANCE_WORDS_TABLE,
        rendered,
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
            page,
            tableKey: HILOS_MAINTENANCE_WORDS_TABLE,
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
