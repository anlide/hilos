// The request a declared bulk operation sends and the acceptance it reads back —
// the two halves of the wire a page's `HilosTableBulkAction.run` needs and would
// otherwise spell out for itself. The shape is the framework's (the backend
// `TableBulkActionDTO` and `TableBulkAcceptedReplyDTO`); only the action name
// belongs to the page, so a page writes its `run` as one dispatch over these.

import { z } from 'zod'

import { type HilosTableBulkAccepted } from './tableBulk.js'
import { type HilosTableSelectionTarget } from './tableSelection.js'

/** Payload key of the table a bulk run is over (backend `TableConstants::PAYLOAD_KEY_TABLE_KEY`). */
const BULK_TABLE_KEY = 'tableKey'

/** Payload key of the rows named one by one (backend `TableConstants::PAYLOAD_KEY_ROW_KEYS`). */
const BULK_ROW_KEYS = 'rowKeys'

/** Payload key of the condition describing the rows (backend `SignalPayloadConstants::FIELD_FILTER`). */
const BULK_FILTER = 'filter'

/**
 * The payload of a bulk action over one table: the table and exactly one of the
 * two targets. The target not chosen is absent rather than null, the backend
 * reading a null beside a value as a second target and refusing both.
 *
 * @param tableKey The table the run is over.
 * @param target What the selection panel says the run is over.
 * @returns The action payload.
 */
export function hilosTableBulkPayload(
  tableKey: string,
  target: HilosTableSelectionTarget,
): Record<string, unknown> {
  return target.kind === 'rows'
    ? { [BULK_TABLE_KEY]: tableKey, [BULK_ROW_KEYS]: [...target.rowKeys] }
    : { [BULK_TABLE_KEY]: tableKey, [BULK_FILTER]: target.filter }
}

/**
 * The acceptance a bulk action replies with. The backend leaves `total` out when
 * the run has no honest count, which reads here as null.
 */
export const hilosTableBulkAcceptedSchema = z
  .looseObject({
    progressKey: z.string(),
    total: z.number().optional(),
  })
  .transform(
    (reply): HilosTableBulkAccepted => ({
      progressKey: reply.progressKey,
      total: reply.total ?? null,
    }),
  )
