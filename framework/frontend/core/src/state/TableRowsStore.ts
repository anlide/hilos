// The table-rows store of ONE scope: rows keyed by table, each row carrying
// its rowKey, its own and computed cells, and EntityRef references for
// entity-derived slots — the row renders by resolving those references
// against the entity store (data-model.md). A rowKey is a row's identity
// WITHIN its table, never conflated with an entity id. Step 7.3.3 semantics
// are snapshot-replace; the pending/Apply delta model arrives at 7.7.

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

/**
 * One normalized table row: the slots hold what the normalizer left behind —
 * an `EntityRef` where the payload slot was an entity fragment, the plain
 * value otherwise.
 */
export interface TableRow {
  /** The row's identity within its table (ordering and pending changes). */
  readonly rowKey: string | number
  /** Source slots by sourceKey: `EntityRef` or the slot's own value. */
  readonly slots: Readonly<Record<string, unknown>>
}

/** One table's current row set, replaced as a whole on every snapshot. */
export interface TableSnapshot {
  /** Rows in the order the backend serialized them. */
  readonly rows: readonly TableRow[]
}

export class TableRowsStore {
  /** Cells are created lazily, so a table can be watched before its rows arrive. */
  private readonly cells = new Map<
    string,
    WritableSignal<TableSnapshot | undefined>
  >()

  /**
   * Replace a table's row set with a fresh snapshot — replace as a whole,
   * never mutate (snapshot-only semantics until the 7.7 delta model).
   *
   * @param tableKey The table the snapshot belongs to.
   * @param rows The normalized rows in backend order.
   */
  replace(tableKey: string, rows: readonly TableRow[]): void {
    this.cell(tableKey).set({ rows })
  }

  /**
   * The table's reactive snapshot: `undefined` until the first replace, then
   * every fresh row set.
   *
   * @param tableKey The table to watch.
   */
  signal(tableKey: string): ReadonlySignal<TableSnapshot | undefined> {
    return this.cell(tableKey)
  }

  private cell(tableKey: string): WritableSignal<TableSnapshot | undefined> {
    let cell = this.cells.get(tableKey)
    if (!cell) {
      cell = createSignal<TableSnapshot | undefined>(undefined)
      this.cells.set(tableKey, cell)
    }

    return cell
  }
}
