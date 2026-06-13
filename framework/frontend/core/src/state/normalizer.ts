// The normalizer — the ONE ingest boundary of the data model. The backend
// prepares, per subscription, the exact structure the frontend receives, and
// exactly this layer may touch that raw payload: entity fragments are upserted
// into the scope's entity store and replaced by EntityRef references; plain
// data lands in the data store as-is; list items land in the list store, their
// entity-bearing slots upserted and referenced exactly like an entity slot, so
// an entity update fans out through the entity store and is never re-streamed
// per list. Everything downstream reads selectors only — reshaping or spreading
// a raw payload into ad-hoc local state is a gross Hilos violation. Table rows,
// the remaining payload kind, arrive with the table-subscription step.
//
// The payload shape is the SDK-side contract derived from data-model.md; the
// wire form is confirmed against it at the page-subscription contract gate.

import { type EntityId, type EntityRef } from './EntityStore.js'
import { type Scope } from './ScopeManager.js'

/**
 * One entity's curated projection inside a payload slot. The stable `id` is
 * what makes the slot an entity by convention; the fragment is stored as-is,
 * `id` included.
 */
export type EntityFragment = { id: EntityId } & Record<string, unknown>

/**
 * One list item on the wire: its identity/order key plus its slots. A slot is
 * an entity fragment (or list of them) or a plain value, told apart the same
 * way an entity slot is — see {@link normalizeSlot}.
 */
export interface ListItemFragment {
  itemKey: EntityId
  slots: Record<string, unknown>
}

/**
 * One list collection on the wire: an incremental delivery of items and the
 * keys removed since the last one. A snapshot upserts every item with no
 * deletes; a delta carries only the changed items and the removed keys. When
 * `cleared` is set the whole list is truncated first — the source collection was
 * cleared backend-side (a deleteAll/DB_SYNC_CLEARED) — and any `items` in the
 * same section then apply on top of the empty list.
 */
export interface ListSection {
  cleared?: boolean
  items?: ListItemFragment[]
  deleted?: EntityId[]
}

/** A scope-shaped payload: entity slots, the scope's own data, and its lists. */
export interface ScopePayload {
  /**
   * Entity fragments by source slot. A slot's `sourceKey` is a binding-local
   * alias, not a type — see {@link NormalizerOptions.entityTypes}.
   */
  entities?: Record<string, EntityFragment | EntityFragment[]>

  /** The scope's non-table, non-entity data: scalars and blobs by name. */
  data?: Record<string, unknown>

  /** Ordered list collections by list key. */
  lists?: Record<string, ListSection>
}

export interface NormalizerOptions {
  /**
   * sourceKey → canonical entityType, for slots whose binding alias differs
   * from the type (e.g. `db_author` → `user`) or that share one type. A slot
   * without an override is its own entityType. The same map governs entity
   * slots inside list items, so a list's references dedupe against entities
   * delivered elsewhere.
   */
  entityTypes?: Record<string, string>
}

/**
 * Ingest a payload into its scope: upsert every entity slot's fragments and
 * leave references in the data store, store plain data as-is, and fold each
 * list's items and deletions into the list store. Entity-bearing list slots
 * are upserted and referenced just like top-level entity slots.
 *
 * @param scope The scope the subscription delivered this payload for.
 * @param payload The raw scope-shaped payload.
 * @param options Binding-local entity-type overrides for this payload's slots.
 */
export function ingest(
  scope: Scope,
  payload: ScopePayload,
  options: NormalizerOptions = {},
): void {
  const slots = payload.entities ?? {}
  const data = payload.data ?? {}
  const lists = payload.lists ?? {}
  for (const key of Object.keys(data)) {
    if (key in slots) {
      throw new Error(
        `Payload key '${key}' is both an entity slot and a data key`,
      )
    }
  }
  for (const key of Object.keys(lists)) {
    if (key in slots) {
      throw new Error(`Payload key '${key}' is both a list and an entity slot`)
    }
    if (key in data) {
      throw new Error(`Payload key '${key}' is both a list and a data key`)
    }
  }

  for (const [sourceKey, fragments] of Object.entries(slots)) {
    const type = entityTypeFor(sourceKey, options)
    scope.data.set(
      sourceKey,
      Array.isArray(fragments)
        ? fragments.map((fragment) =>
            ingestFragment(scope, type, sourceKey, fragment),
          )
        : ingestFragment(scope, type, sourceKey, fragments),
    )
  }
  for (const [key, value] of Object.entries(data)) {
    scope.data.set(key, value)
  }
  for (const [listKey, section] of Object.entries(lists)) {
    ingestList(scope, listKey, section, options)
  }
}

function ingestList(
  scope: Scope,
  listKey: string,
  section: ListSection,
  options: NormalizerOptions,
): void {
  if (section.cleared) {
    scope.lists.clear(listKey)
  }
  for (const item of section.items ?? []) {
    const slots: Record<string, unknown> = {}
    for (const [sourceKey, value] of Object.entries(item.slots)) {
      slots[sourceKey] = normalizeSlot(scope, sourceKey, value, options)
    }
    scope.lists.upsert(listKey, item.itemKey, slots)
  }
  for (const itemKey of section.deleted ?? []) {
    scope.lists.delete(listKey, itemKey)
  }
}

/**
 * Normalize one list-item slot: an entity fragment (or array of them) is
 * upserted and reduced to an `EntityRef` (or `EntityRef[]`); anything without a
 * stable `id` — a scalar, a blob, a foreign-key value — is kept as-is. This is
 * the permissive sibling of the entity-section rule, where every slot must be
 * an entity.
 */
function normalizeSlot(
  scope: Scope,
  sourceKey: string,
  value: unknown,
  options: NormalizerOptions,
): unknown {
  const type = entityTypeFor(sourceKey, options)
  if (Array.isArray(value)) {
    return value.map((element) =>
      isEntityFragment(element)
        ? ingestFragment(scope, type, sourceKey, element)
        : element,
    )
  }
  if (isEntityFragment(value)) {
    return ingestFragment(scope, type, sourceKey, value)
  }

  return value
}

/** A slot value is an entity iff it is an object bearing a non-null `id`. */
function isEntityFragment(value: unknown): value is EntityFragment {
  return (
    typeof value === 'object' &&
    value !== null &&
    !Array.isArray(value) &&
    (value as { id?: unknown }).id != null
  )
}

function entityTypeFor(sourceKey: string, options: NormalizerOptions): string {
  return options.entityTypes?.[sourceKey] ?? sourceKey
}

function ingestFragment(
  scope: Scope,
  type: string,
  sourceKey: string,
  fragment: EntityFragment,
): EntityRef {
  // Runtime guard for payloads that reach the boundary untyped.
  const { id } = fragment as { id?: EntityId | null }
  if (id == null) {
    throw new Error(`Entity fragment in slot '${sourceKey}' has no stable id`)
  }
  const ref: EntityRef = { type, id }
  scope.entities.upsert(ref, fragment)

  return ref
}
