// The normalizer — the ONE ingest boundary of the data model. The backend
// prepares, per subscription, the exact structure the frontend receives, and
// exactly this layer may touch that raw payload: entity fragments are
// upserted into the scope's entity store and replaced by EntityRef references
// in the scope's data store; plain data lands in the data store as-is; table
// rows land in the scope's table-rows store with their entity-fragment slots
// likewise replaced by references. Everything downstream reads selectors only
// — reshaping or spreading a raw payload into ad-hoc local state is a gross
// Hilos violation.
//
// The payload shape is the SDK-side contract derived from data-model.md; the
// wire form (protocol/scopePayload.ts) was confirmed against it at the
// page-subscription contract gate.

import { type EntityId, type EntityRef } from './EntityStore.js'
import { type Scope } from './ScopeManager.js'
import { type TableRow } from './TableRowsStore.js'

/**
 * One entity's curated projection inside a payload slot. The stable `id` is
 * what makes the slot an entity by convention; the fragment is stored as-is,
 * `id` included.
 */
export type EntityFragment = { id: EntityId } & Record<string, unknown>

/**
 * One table row as the payload carries it: the row's identity within its
 * table plus its source slots. A slot bearing a stable `id` is an entity
 * fragment by convention; any other slot value is the row's own cell.
 */
export interface TableRowPayload {
  rowKey: EntityId
  slots: Record<string, unknown>
}

/** One table's payload: its full row set in backend order (snapshot-only). */
export interface TablePayload {
  rows: TableRowPayload[]
}

/** A scope-shaped payload: entity slots, the scope's own data, and its tables. */
export interface ScopePayload {
  /**
   * Entity fragments by source slot. A slot's `sourceKey` is a binding-local
   * alias, not a type — see {@link NormalizerOptions.entityTypes}.
   */
  entities?: Record<string, EntityFragment | EntityFragment[]>

  /** The scope's non-table, non-entity data: scalars and blobs by name. */
  data?: Record<string, unknown>

  /** Table row sets by tableKey. */
  tables?: Record<string, TablePayload>
}

export interface NormalizerOptions {
  /**
   * sourceKey → canonical entityType, for slots whose binding alias differs
   * from the type (e.g. `db_author` → `user`) or that share one type. A slot
   * without an override is its own entityType.
   */
  entityTypes?: Record<string, string>
}

/**
 * Ingest a payload into its scope: upsert every slot's fragments into the
 * scope's entity store, leave an `EntityRef` (or an order-preserving list of
 * them) under the slot's `sourceKey` in the scope's data store, store the
 * plain data as-is, and replace each table's row set with its normalized
 * snapshot — row slots holding entity fragments become references the same
 * way.
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
  for (const key of Object.keys(data)) {
    if (key in slots) {
      throw new Error(
        `Payload key '${key}' is both an entity slot and a data key`,
      )
    }
  }

  for (const [sourceKey, fragments] of Object.entries(slots)) {
    const type = options.entityTypes?.[sourceKey] ?? sourceKey
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
  for (const [tableKey, table] of Object.entries(payload.tables ?? {})) {
    scope.tables.replace(
      tableKey,
      table.rows.map((row) => normalizeRow(scope, row, options)),
    )
  }
}

/**
 * Normalize one payload row: an entity-fragment slot is upserted and becomes
 * an `EntityRef`; any other slot value stays on the row as-is. A slot list of
 * fragments is not detected here — it stays opaque until a table needs it.
 */
function normalizeRow(
  scope: Scope,
  row: TableRowPayload,
  options: NormalizerOptions,
): TableRow {
  const slots: Record<string, unknown> = {}
  for (const [sourceKey, value] of Object.entries(row.slots)) {
    slots[sourceKey] = isEntityFragment(value)
      ? ingestFragment(
          scope,
          options.entityTypes?.[sourceKey] ?? sourceKey,
          sourceKey,
          value,
        )
      : value
  }

  return { rowKey: row.rowKey, slots }
}

/** The entity convention of data-model.md: a slot value bearing a stable id. */
function isEntityFragment(value: unknown): value is EntityFragment {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false
  }
  const { id } = value as { id?: unknown }

  return typeof id === 'string' || typeof id === 'number'
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
