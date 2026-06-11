// The normalizer — the ONE ingest boundary of the data model. The backend
// prepares, per subscription, the exact structure the frontend receives, and
// exactly this layer may touch that raw payload: entity fragments are
// upserted into the scope's entity store and replaced by EntityRef references
// in the scope's data store; plain data lands in the data store as-is.
// Everything downstream reads selectors only — reshaping or spreading a raw
// payload into ad-hoc local state is a gross Hilos violation. Table rows, the
// remaining payload kind, arrive with the table-subscription step.
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

/** A scope-shaped payload: entity slots plus the scope's own data. */
export interface ScopePayload {
  /**
   * Entity fragments by source slot. A slot's `sourceKey` is a binding-local
   * alias, not a type — see {@link NormalizerOptions.entityTypes}.
   */
  entities?: Record<string, EntityFragment | EntityFragment[]>

  /** The scope's non-table, non-entity data: scalars and blobs by name. */
  data?: Record<string, unknown>
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
 * them) under the slot's `sourceKey` in the scope's data store, and store the
 * plain data as-is.
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
