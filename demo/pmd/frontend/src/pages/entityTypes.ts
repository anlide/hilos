// This demo's page payloads' entity-slot types: which payload slots are entities and
// under what canonical type. Frontend config, not emitted on the wire — keep it in
// sync with the backend browser sources (rules-and-violations.md). A wire slot key
// is its backend collection name (`PmdDbContext::*`); the canonical type
// makes a row dedupe against the same entity wherever it appears. The binder
// (`bindPageScope`, applied inside bootHilos) applies this to every page payload's
// slots — here, `users` on the Hilos users/user admin pages and `sessions` in
// the profile's durable browser-session list, plus `pushSubscriptions` in
// the profile's devices list.
import { USER_ENTITY_TYPE } from '@hilos/core/base'

import { PUSH_SUBSCRIPTION_TYPE } from '../types/PushSubscription.js'
import { SESSION_TYPE } from '../types/Session.js'

/** Per-slot canonical entity types for this demo's page payloads. */
export const pageEntityTypes: Record<string, string> = {
  users: USER_ENTITY_TYPE,
  sessions: SESSION_TYPE,
  pushSubscriptions: PUSH_SUBSCRIPTION_TYPE,
}
