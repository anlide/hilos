import { createSignal, type ReadonlySignal } from '../state/signal.js'

/**
 * Logical text intent for one browser controller. The controller validates
 * geometry-issued occurrences and columns before writing. Only open pairs
 * occupy state; neither row data nor projection/DOM measurements belong here.
 * Hilos::$rt has no reader for this mount-local presentation preference
 * (runtime/rt-context.md and architecture/truth-source.md).
 */
export class TableTextExpansion {
  private readonly expandedSignal = createSignal<
    ReadonlyMap<number, ReadonlySet<string>>
  >(new Map())

  readonly expanded: ReadonlySignal<ReadonlyMap<number, ReadonlySet<string>>> =
    { get: () => this.expandedSignal.get() }

  /** Set one validated occurrence/column pair; repeating an intent is inert. */
  setExpanded(occurrence: number, columnKey: string, expanded: boolean): void {
    const current = this.expandedSignal.get()
    const previous = current.get(occurrence)
    if ((previous?.has(columnKey) ?? false) === expanded) {
      return
    }
    const columns = new Set(previous)
    if (expanded) {
      columns.add(columnKey)
    } else {
      columns.delete(columnKey)
    }
    const next = new Map(current)
    if (columns.size === 0) {
      next.delete(occurrence)
    } else {
      next.set(occurrence, columns)
    }
    this.expandedSignal.set(next)
  }

  /** Release every column of a departed, evicted, or replaced occurrence. */
  forgetOccurrence(occurrence: number): void {
    if (!this.expandedSignal.get().has(occurrence)) {
      return
    }
    const next = new Map(this.expandedSignal.get())
    next.delete(occurrence)
    this.expandedSignal.set(next)
  }

  /** Removed/nonparticipating columns cannot leave intent for a later reuse. */
  retainColumns(available: ReadonlySet<string>): void {
    const current = this.expandedSignal.get()
    const next = new Map<number, ReadonlySet<string>>()
    let changed = false
    for (const [occurrence, columns] of current) {
      const retained = new Set([...columns].filter((key) => available.has(key)))
      changed ||= retained.size !== columns.size
      if (retained.size > 0) {
        next.set(occurrence, retained)
      }
    }
    if (changed) {
      this.expandedSignal.set(next)
    }
  }

  /** A new window, reset, refusal, or disposal releases all intent. */
  clear(): void {
    if (this.expandedSignal.get().size > 0) {
      this.expandedSignal.set(new Map())
    }
  }
}
