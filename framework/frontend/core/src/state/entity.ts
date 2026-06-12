// Framework-level entity types. An entity is the typed shape of one normalized
// row in the entity store, the counterpart of an `EntitySnapshot.fields` once a
// project read has typed it. The base carries only the stable `id` every entity
// has by the detection convention (data-model.md); a project extends it with its
// own fields, and the framework ships the entities it owns the contract for so
// every consumer inherits them rather than re-declaring them.

import { type EntityId } from './EntityStore.js'

/**
 * The base of every frontend entity: the stable `id` that the detection
 * convention requires. A project's domain entity extends this with its curated
 * projection fields.
 */
export interface Entity {
  readonly id: EntityId
}

/**
 * The framework user entity. Hilos owns the user's authorization contract, so
 * these fields are part of the framework shape every project inherits — even
 * before a given backend emits them, a project's `User` must carry them, and a
 * light accessor defaults them until the payload does. A project extends this
 * with its own profile fields (display name, activity, …).
 */
export interface User extends Entity {
  /** Holds the superadmin grant, bypassing role checks (RBAC). */
  readonly superadmin: boolean
  /** True while the account is blocked from acting (RBAC). */
  readonly blocked: boolean
}
