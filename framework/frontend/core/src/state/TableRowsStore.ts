// The per-scope table-rows store: each scope owns one, holding its table row
// collections keyed by tableKey. A table is the HEAVY windowed primitive; the
// lighter ordered primitive is the list store. In this first table-subscription
// step the rows arrive the same way a list does — a full snapshot plus live
// per-row deltas applied immediately (create/update in place, delete drops the
// row, clear truncates). The headless viewport (filter/sort/page) and the
// pending/Apply model from table-subscription.md are a later step (7.7) layered
// on top, not in this store. The normalizer is the only writer; a row's
// entity-bearing slots are already EntityRef references by the time they reach
// here, so an entity update fans out through the entity store — it never
// re-streams the table (see normalizer.ts and data-model.md).

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

/** One table row: its identity key within the table plus its normalized slots. */
export interface TableRow {
  /**
   * The stringified row identity, unique within the table. Used for ordering
   * and per-row updates; it is NOT a DB id, though it may coincide with one.
   */
  readonly rowKey: string
  /**
   * Normalized slots: an entity slot holds an `EntityRef` (or an
   * order-preserving `EntityRef[]`), a plain slot holds its value as-is. The
   * store treats them opaquely; downstream code resolves references reactively.
   */
  readonly slots: Readonly<Record<string, unknown>>
}

export class TableRowsStore {
  /** Cells are created lazily, so a table can be watched before its first row arrives. */
  private readonly cells = new Map<
    string,
    WritableSignal<readonly TableRow[]>
  >()

  /**
   * Create or update one row: a new `rowKey` is appended at the end, an
   * existing one is replaced in place (its position is preserved). The slots
   * replace the row's previous slots as a whole — incremental field merging
   * already happened in the entity store, not here. The view's headless
   * controller re-sorts and re-pages; the store keeps backend arrival order.
   *
   * @param tableKey The table the row belongs to.
   * @param rowKey The row's identity key; `1` and `'1'` name the same row.
   * @param slots The row's normalized slots (entity refs and plain values).
   */
  upsert(
    tableKey: string,
    rowKey: string | number,
    slots: Readonly<Record<string, unknown>>,
  ): void {
    const key = String(rowKey)
    const cell = this.cell(tableKey)
    const rows = cell.get()
    const row: TableRow = { rowKey: key, slots }
    const index = rows.findIndex((existing) => existing.rowKey === key)
    if (index === -1) {
      cell.set([...rows, row])

      return
    }
    const next = rows.slice()
    next[index] = row
    cell.set(next)
  }

  /**
   * Drop one row from a table; a no-op when the row is absent, so a delete for
   * an already-removed key is harmless.
   *
   * @param tableKey The table to remove from.
   * @param rowKey The row's identity key.
   */
  delete(tableKey: string, rowKey: string | number): void {
    const key = String(rowKey)
    const cell = this.cell(tableKey)
    const rows = cell.get()
    const next = rows.filter((existing) => existing.rowKey !== key)
    if (next.length !== rows.length) {
      cell.set(next)
    }
  }

  /**
   * Drop every row from a table (truncate); a no-op when the table is already
   * empty. The backend source was cleared, so the table resets to empty and any
   * rows delivered alongside the clear are upserted afterwards.
   *
   * @param tableKey The table to clear.
   */
  clear(tableKey: string): void {
    const cell = this.cell(tableKey)
    if (cell.get().length !== 0) {
      cell.set([])
    }
  }

  /**
   * The table's reactive rows in backend arrival order; an empty array until
   * the first upsert.
   *
   * @param tableKey The table to watch.
   */
  signal(tableKey: string): ReadonlySignal<readonly TableRow[]> {
    return this.cell(tableKey)
  }

  private cell(tableKey: string): WritableSignal<readonly TableRow[]> {
    let cell = this.cells.get(tableKey)
    if (!cell) {
      cell = createSignal<readonly TableRow[]>([])
      this.cells.set(tableKey, cell)
    }

    return cell
  }
}
