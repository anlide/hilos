// Session-scope bootstrap: route the backend's handshake response into the
// session scope and expose the current user reactively. The session-scope
// analog of bindPageScope — lifted from every project so the handshake plumbing
// and the current-user selector live once
// (docs/agents/frontend/bootstrap-structure.md).
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  scopePayloadSchema,
  type ScopePayloadWire,
} from '../protocol/scopePayload.js'
import { type EntityRef } from '../state/EntityStore.js'
import { readString } from '../state/fieldReaders.js'
import { ingest } from '../state/normalizer.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../state/signal.js'

/**
 * The session-scope response the backend sends after the handshake, carrying the
 * session scope payload. Each project keeps its backend signal byte-equal (e.g.
 * `ChatSignalConstants::HANDSHAKE_RESPONSE`).
 */
const SIGNAL_HANDSHAKE_RESPONSE = 'handshake_response'

const DEFAULT_CURRENT_USER_SLOT = 'currentUser'
const DEFAULT_CURRENT_USER_ENTITY_TYPE = 'user'
const DEFAULT_CURRENT_USER_NAME_FIELD = 'name'

/**
 * The handshake-response payload keyed for a connection's `projectSchemas`, so
 * the parse boundary validates what {@link bindSessionScope} ingests.
 * {@link createHilosConnection} merges it in, so a project never restates the
 * `{ handshake_response: scopePayloadSchema }` pair.
 */
export const SESSION_SIGNAL_SCHEMAS = {
  [SIGNAL_HANDSHAKE_RESPONSE]: scopePayloadSchema,
}

/** Where the current user sits in the session scope, and which field names it. */
export interface SessionScopeOptions {
  /** Session-scope slot the current user arrives under. Default `currentUser`. */
  currentUserSlot?: string
  /** Canonical entity type for that slot. Default `user`. */
  currentUserEntityType?: string
  /** Entity field holding the display name. Default `name`. */
  currentUserNameField?: string
}

/**
 * Ingest every handshake response into the session scope, resolving the current
 * user under its slot. Register this before the socket opens so the first
 * response lands.
 *
 * @param connection The application's Hilos connection.
 * @param scopes The application's scope-partitioned stores.
 * @param options Current-user slot and entity-type overrides.
 */
export function bindSessionScope(
  connection: HilosConnection,
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): void {
  const slot = options.currentUserSlot ?? DEFAULT_CURRENT_USER_SLOT
  const entityType =
    options.currentUserEntityType ?? DEFAULT_CURRENT_USER_ENTITY_TYPE
  connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
      // Validated against scopePayloadSchema at the parse boundary; this cast is
      // the declared typed selector for that schema's output.
      ingest(scopes.session, signal.data as ScopePayloadWire, {
        entityTypes: { [slot]: entityType },
      })
    }
  })
}

/**
 * The current user's display name; empty until the handshake response lands.
 * Derived once from the session scope so a project never restates the selector.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Current-user slot and name-field overrides.
 */
export function sessionUserName(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<string> {
  const slot = options.currentUserSlot ?? DEFAULT_CURRENT_USER_SLOT
  const field = options.currentUserNameField ?? DEFAULT_CURRENT_USER_NAME_FIELD
  // The normalizer leaves an EntityRef under the slot's sourceKey.
  const currentUserRef = scopes.session.data.signal(slot) as ReadonlySignal<
    EntityRef | undefined
  >

  return computedSignal(() => {
    const ref = currentUserRef.get()
    if (!ref) {
      return ''
    }
    const snapshot = scopes.entitySignal(ref).get()

    return snapshot ? readString(snapshot.fields, field) : ''
  })
}
