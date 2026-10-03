// Framework-level entity types. An entity is the typed shape of one normalized
// row in the entity store, the counterpart of an `EntitySnapshot.fields` once a
// project read has typed it. The base carries only the stable `id` every entity
// has by the detection convention (data-model.md); a project extends it with its
// own fields, and the framework ships the entities it owns the contract for so
// every consumer inherits them rather than re-declaring them. For the person the
// framework ships, beside the type, its projector and its entity type name, so a
// project binds only its own collection.

import { type EntityId } from './EntityStore.js'
import {
  readBoolean,
  readNumber,
  readString,
  readStringOrNull,
} from './fieldReaders.js'

/**
 * The base of every frontend entity: the stable `id` that the detection
 * convention requires. A project's domain entity extends this with its curated
 * projection fields.
 */
export interface Entity {
  readonly id: EntityId
}

/**
 * The framework person entity — the frontend twin of the people table
 * `hilos_user` (docs/agents/architecture/people-table.md). Hilos owns the whole
 * person, so every field below is part of the framework shape every project
 * inherits. A project extends this type only with a field of its own, and reads
 * it on top of `userFromFields`.
 */
export interface User extends Entity {
  /** Whether the user is a panel admin operator (RBAC). */
  readonly admin: boolean
  /** Whether the user is blocked from acting (RBAC). */
  readonly block: boolean
  /** Display name. */
  readonly name: string
  /** Published profile photo variant URL, or null for initials. */
  readonly photo: string | null
  /** Last activity timestamp, or null when never recorded. */
  readonly lastActivity: string | null
}

/**
 * The canonical entity type of the person — the frontend twin of the backend
 * `HilosDbContext::user` key. A person arriving in any slot deduplicates on it
 * into one entity (data-model.md: one entity per (type,id) per scope).
 */
export const USER_ENTITY_TYPE = 'user'

/**
 * Project a committed person's raw fields into the typed entity. A project with
 * a field of its own extends the result: `{ ...userFromFields(fields), own: … }`.
 *
 * @param fields The person entity's committed fields.
 */
export function userFromFields(
  fields: Readonly<Record<string, unknown>>,
): User {
  return {
    id: readNumber(fields, 'id'),
    admin: readBoolean(fields, 'admin'),
    block: readBoolean(fields, 'block'),
    name: readString(fields, 'name'),
    photo: readStringOrNull(fields, 'photo'),
    lastActivity: readStringOrNull(fields, 'lastActivity'),
  }
}
