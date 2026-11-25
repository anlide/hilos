// The per-scope data store: each scope owns one, holding its non-table,
// non-entity data — scalars and blobs keyed by name — plus the EntityRef
// references the normalizer leaves in place of entity slots. Values are
// opaque to the store; downstream code reads them through typed selectors.

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

export interface DataEntry {
  readonly value: unknown
  readonly pageSubscriptionProof?: string
}

interface DataCell {
  readonly entry: WritableSignal<DataEntry | undefined>
  readonly value: ReadonlySignal<unknown>
}

export class DataStore {
  /** Cells are created lazily, so a key can be watched before its value arrives. */
  private readonly cells = new Map<string, DataCell>()

  /**
   * Replace the value under a key — replace as a whole, never mutate
   * (subscribers fire on `Object.is` change).
   *
   * @param key The datum's name within the scope.
   * @param value The new value.
   * @param pageSubscriptionProof The proof delivered with this value.
   */
  set(key: string, value: unknown, pageSubscriptionProof?: string): void {
    const { entry } = this.cell(key)
    const previous = entry.get()
    if (
      Object.is(previous?.value, value) &&
      previous?.pageSubscriptionProof === pageSubscriptionProof
    ) {
      return
    }
    entry.set(
      value === undefined ? undefined : { value, pageSubscriptionProof },
    )
  }

  /**
   * The reactive value under a key; `undefined` until the first set.
   *
   * @param key The datum's name within the scope.
   */
  signal(key: string): ReadonlySignal<unknown> {
    return this.cell(key).value
  }

  /**
   * The datum and the proof that arrived with it, published in one reactive write.
   *
   * @param key The datum's name within the scope.
   */
  entrySignal(key: string): ReadonlySignal<DataEntry | undefined> {
    return this.cell(key).entry
  }

  private cell(key: string): DataCell {
    let cell = this.cells.get(key)
    if (!cell) {
      const entry = createSignal<DataEntry | undefined>(undefined)
      cell = { entry, value: { get: () => entry.get()?.value } }
      this.cells.set(key, cell)
    }

    return cell
  }
}
