// The per-scope data store: each scope owns one, holding its non-table,
// non-entity data — scalars and blobs keyed by name — plus the EntityRef
// references the normalizer leaves in place of entity slots. Values are
// opaque to the store; downstream code reads them through typed selectors.

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

export class DataStore {
  /** Cells are created lazily, so a key can be watched before its value arrives. */
  private readonly cells = new Map<string, WritableSignal<unknown>>()

  /**
   * Replace the value under a key — replace as a whole, never mutate
   * (subscribers fire on `Object.is` change).
   *
   * @param key The datum's name within the scope.
   * @param value The new value.
   */
  set(key: string, value: unknown): void {
    this.cell(key).set(value)
  }

  /**
   * The reactive value under a key; `undefined` until the first set.
   *
   * @param key The datum's name within the scope.
   */
  signal(key: string): ReadonlySignal<unknown> {
    return this.cell(key)
  }

  private cell(key: string): WritableSignal<unknown> {
    let cell = this.cells.get(key)
    if (!cell) {
      cell = createSignal<unknown>(undefined)
      this.cells.set(key, cell)
    }

    return cell
  }
}
