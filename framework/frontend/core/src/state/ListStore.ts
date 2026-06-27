// The per-scope list store: each scope owns one, holding its ordered list
// collections — chat logs, catalogs, option sets — keyed by listKey. A list is
// the LIGHT ordered primitive (no viewport, no pending/Apply); the heavy
// windowed primitive is the table-rows store. Each list is an ordered sequence
// of items keyed by `itemKey`, fed incrementally: create appends at the end,
// update replaces an item in place, delete drops it. The normalizer is the only
// writer; an item's entity-bearing slots are already EntityRef references by the
// time they reach here, so an entity update fans out through the entity store —
// it never re-streams the list (see normalizer.ts and data-model.md).

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

/** One list item: its identity/order key plus its normalized slots. */
export interface ListItem {
  /** The stringified identity key, unique and order-bearing within the list. */
  readonly itemKey: string
  /**
   * Normalized slots: an entity slot holds an `EntityRef` (or an
   * order-preserving `EntityRef[]`), a plain slot holds its value as-is. The
   * store treats them opaquely; downstream code resolves references reactively.
   */
  readonly slots: Readonly<Record<string, unknown>>
}

export class ListStore {
  /** Cells are created lazily, so a list can be watched before its first item arrives. */
  private readonly cells = new Map<
    string,
    WritableSignal<readonly ListItem[]>
  >()

  /**
   * Create or update one item: a new `itemKey` is appended at the end, an
   * existing one is replaced in place (its position is preserved). The slots
   * replace the item's previous slots as a whole — incremental field merging
   * already happened in the entity store, not here.
   *
   * @param listKey The list the item belongs to.
   * @param itemKey The item's identity key; `1` and `'1'` name the same item.
   * @param slots The item's normalized slots (entity refs and plain values).
   */
  upsert(
    listKey: string,
    itemKey: string | number,
    slots: Readonly<Record<string, unknown>>,
  ): void {
    const key = String(itemKey)
    const cell = this.cell(listKey)
    const items = cell.get()
    const item: ListItem = { itemKey: key, slots }
    const index = items.findIndex((existing) => existing.itemKey === key)
    if (index === -1) {
      cell.set([...items, item])

      return
    }
    const next = items.slice()
    next[index] = item
    cell.set(next)
  }

  /**
   * Drop one item from a list; a no-op when the item is absent, so a delete for
   * an already-removed key is harmless.
   *
   * @param listKey The list to remove from.
   * @param itemKey The item's identity key.
   */
  delete(listKey: string, itemKey: string | number): void {
    const key = String(itemKey)
    const cell = this.cell(listKey)
    const items = cell.get()
    const next = items.filter((existing) => existing.itemKey !== key)
    if (next.length !== items.length) {
      cell.set(next)
    }
  }

  /**
   * Drop every item from a list (truncate); a no-op when the list is already
   * empty. The backend source was cleared, so the list resets to empty and any
   * items delivered alongside the clear are upserted afterwards.
   *
   * @param listKey The list to clear.
   */
  clear(listKey: string): void {
    const cell = this.cell(listKey)
    if (cell.get().length !== 0) {
      cell.set([])
    }
  }

  /**
   * The list's reactive ordered items; an empty array until the first upsert.
   *
   * @param listKey The list to watch.
   */
  signal(listKey: string): ReadonlySignal<readonly ListItem[]> {
    return this.cell(listKey)
  }

  private cell(listKey: string): WritableSignal<readonly ListItem[]> {
    let cell = this.cells.get(listKey)
    if (!cell) {
      cell = createSignal<readonly ListItem[]>([])
      this.cells.set(listKey, cell)
    }

    return cell
  }
}
