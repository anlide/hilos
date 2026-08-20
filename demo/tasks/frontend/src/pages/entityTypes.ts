// The tasks page payloads' entity-slot types: which payload slots are entities and
// under what canonical type. Frontend config, not emitted on the wire — keep it in
// sync with the backend browser sources (rules-and-violations.md). A wire slot key
// is its backend collection name (`TasksDbContext::*`); the canonical type makes a
// row dedupe against the same entity wherever it appears. The binder
// (`bindPageScope`, applied inside bootHilos) applies this to every page payload's
// slots — here, the `users` slot of the Hilos users/user admin pages.
import { USER_TYPE } from '../types'

/** Per-slot canonical entity types for the tasks demo's page payloads. */
export const pageEntityTypes: Record<string, string> = {
  users: USER_TYPE,
}
