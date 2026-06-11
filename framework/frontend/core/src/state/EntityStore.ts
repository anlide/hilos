// The normalized entity store of ONE scope: each entity lives once, keyed by
// (entityType, id), and is referenced everywhere else — an entity appearing in
// fifty rows is stored once and referenced fifty times. The store holds only
// the COMMITTED server value: uncommitted edit drafts stay with the editing
// modal, never here. Scope ownership, lifetimes, and cross-scope resolution
// live in ScopeManager.ts.

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

/** An entity's stable id as delivered by the payload. */
export type EntityId = string | number

/**
 * A reference to an entity — a small value resolved through a selector; data
 * lives once, references are everywhere.
 */
export interface EntityRef {
  /** The CANONICAL entity type — never a binding-local sourceKey alias. */
  type: string
  /** Stable id; `1` and `'1'` name the same entity (ids stringify in the key). */
  id: EntityId
}

/** One entity's committed state inside one scope. */
export interface EntitySnapshot {
  readonly type: string
  /** The stringified stable id. */
  readonly id: string
  /** Committed server fields; replaced as a whole on upsert, never mutated. */
  readonly fields: Readonly<Record<string, unknown>>
  /**
   * Local upsert counter (1 on first appearance). NOT the server's entity
   * version — that field arrives with optimistic concurrency at step 7.5.
   */
  readonly revision: number
}

export class EntityStore {
  /** Cells are created lazily, so a reference can be watched before its entity arrives. */
  private readonly cells = new Map<
    string,
    WritableSignal<EntitySnapshot | undefined>
  >()

  /**
   * Field-wise merge of a payload fragment into the entity, creating it on
   * first sight: a field present in the fragment overwrites, a field ABSENT is
   * left untouched (absence is not null), an explicit clear is an explicit
   * `null` value.
   *
   * @param ref The entity the fragment belongs to.
   * @param fragment Field subset from the payload (JSON-shaped: absence is an omitted key).
   */
  upsert(ref: EntityRef, fragment: Readonly<Record<string, unknown>>): void {
    const cell = this.cell(ref)
    const current = cell.get()
    cell.set({
      type: ref.type,
      id: String(ref.id),
      fields: { ...current?.fields, ...fragment },
      revision: (current?.revision ?? 0) + 1,
    })
  }

  /**
   * The entity's reactive snapshot: `undefined` until the first upsert, then
   * every committed change — one signal per (entityType, id), shared by all
   * references.
   *
   * @param ref The entity to watch.
   */
  signal(ref: EntityRef): ReadonlySignal<EntitySnapshot | undefined> {
    return this.cell(ref)
  }

  private cell(ref: EntityRef): WritableSignal<EntitySnapshot | undefined> {
    // NUL never occurs in a type name, so the compound key cannot collide.
    const key = `${ref.type}\u0000${String(ref.id)}`
    let cell = this.cells.get(key)
    if (!cell) {
      cell = createSignal<EntitySnapshot | undefined>(undefined)
      this.cells.set(key, cell)
    }

    return cell
  }
}
