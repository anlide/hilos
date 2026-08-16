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
export const SIGNAL_HANDSHAKE_RESPONSE = 'handshake_response'

const DEFAULT_CURRENT_USER_SLOT = 'currentUser'
const DEFAULT_CURRENT_USER_ENTITY_TYPE = 'user'
const DEFAULT_CURRENT_USER_NAME_FIELD = 'name'
const DEFAULT_CURRENT_USER_ADMIN_FIELD = 'admin'
const DEFAULT_IMPERSONATED_BY_SLOT = 'impersonatedBy'
const DEFAULT_PENDING_ACK_SLOT = 'pendingAck'

/**
 * A registration whose confirmation just landed the person inside. Byte-equal to
 * the backend `SessionAck::REGISTERED` — the value IS the contract, so the two
 * sides spell it out rather than deriving it.
 */
export const SESSION_ACK_REGISTERED = 'auth_registered'

/** A recovery whose new password was just saved (`SessionAck::PASSWORD_CHANGED`). */
export const SESSION_ACK_PASSWORD_CHANGED = 'auth_password_changed'

/** A magic link that just signed the person in (`SessionAck::SIGNED_IN`). */
export const SESSION_ACK_SIGNED_IN = 'auth_signed_in'

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
  /**
   * Session-scope slot the impersonating admin arrives under while the session is
   * being impersonated (non-null ⇒ impersonating). Shares the current-user entity
   * type and name field. Default `impersonatedBy`.
   */
  impersonatedBySlot?: string
  /**
   * Plain session-scope key carrying the ack the connection still owes its
   * person, or null when it owes none. Default `pendingAck`.
   */
  pendingAckSlot?: string
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
  const impersonatedBySlot =
    options.impersonatedBySlot ?? DEFAULT_IMPERSONATED_BY_SLOT
  const entityType =
    options.currentUserEntityType ?? DEFAULT_CURRENT_USER_ENTITY_TYPE
  connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
      // Validated against scopePayloadSchema at the parse boundary; this cast is
      // the declared typed selector for that schema's output. The impersonating
      // admin shares the current-user entity type so it dedupes against the same
      // user delivered elsewhere; a null slot clears it (no longer impersonated).
      const payload = signal.data as ScopePayloadWire
      // The plain section goes in FIRST, and the two-step is the mechanism rather
      // than a detail (HIL-422). `ingest` publishes entity slots before plain data
      // and subscribers run synchronously, so a subscriber of `currentUser` would
      // read the ack of the PREVIOUS response — and the auth gate decides whether
      // the rising session may close the surface exactly by that read. One frame
      // late is the surface closing over the sentence it exists to show. The
      // second pass rewrites the same values, which notifies nobody.
      ingest(scopes.session, { data: payload.data ?? {} })
      ingest(scopes.session, payload, {
        entityTypes: { [slot]: entityType, [impersonatedBySlot]: entityType },
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

/**
 * The current user's id, or null until the handshake response lands. Derived from
 * the same session-scope current-user reference the name selector resolves, so a
 * project never restates it; the id rides the EntityRef, so no entity snapshot
 * lookup is needed.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Current-user slot override.
 */
export function sessionUserId(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<number | null> {
  const slot = options.currentUserSlot ?? DEFAULT_CURRENT_USER_SLOT
  const currentUserRef = scopes.session.data.signal(slot) as ReadonlySignal<
    EntityRef | undefined
  >

  return computedSignal(() => {
    const ref = currentUserRef.get()
    if (!ref) {
      return null
    }
    const id = Number(ref.id)

    return Number.isFinite(id) ? id : null
  })
}

/**
 * Whether the current user holds the admin privilege, false until the handshake
 * response says otherwise. The single source the shell derives its admin entry
 * from, so a project never restates it — and false by default, so a project that
 * answers no admin identity shows no way into a surface it would refuse anyway.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Current-user slot override.
 */
export function sessionUserIsAdmin(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<boolean> {
  const slot = options.currentUserSlot ?? DEFAULT_CURRENT_USER_SLOT
  // The normalizer leaves an EntityRef under the slot's sourceKey.
  const currentUserRef = scopes.session.data.signal(slot) as ReadonlySignal<
    EntityRef | undefined
  >

  return computedSignal(() => {
    const ref = currentUserRef.get()
    if (!ref) {
      return false
    }
    const snapshot = scopes.entitySignal(ref).get()

    return snapshot?.fields[DEFAULT_CURRENT_USER_ADMIN_FIELD] === true
  })
}

/**
 * Whether the session is currently being impersonated: true while the
 * impersonatedBy slot holds a reference (an admin acting as this user), false
 * otherwise. The single source the shell derives its impersonation banner from,
 * so a project never restates the flag; the slot clears when impersonation stops.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Impersonated-by slot override.
 */
export function sessionImpersonating(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<boolean> {
  const slot = options.impersonatedBySlot ?? DEFAULT_IMPERSONATED_BY_SLOT
  const impersonatedByRef = scopes.session.data.signal(slot) as ReadonlySignal<
    EntityRef | undefined
  >

  return computedSignal(() => impersonatedByRef.get() != null)
}

/**
 * The ack this connection still owes its person, or null when it owes none.
 *
 * What a finished auth flow leaves behind so the surface has something to say
 * before it closes (HIL-422). It is per-CONNECTION, not per-session: a reload
 * opens a new socket, which owes nothing, so the announcement does not survive an
 * F5 and needs no expiry. The value is one of the `SESSION_ACK_*` kinds; an
 * unknown string is passed through rather than swallowed, so a client older than
 * the server fails visibly at the view that cannot draw it instead of silently
 * showing nothing.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Pending-ack slot override.
 */
export function sessionPendingAck(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<string | null> {
  const slot = options.pendingAckSlot ?? DEFAULT_PENDING_ACK_SLOT
  const pendingAck = scopes.session.data.signal(slot)

  return computedSignal(() => {
    const ack = pendingAck.get()

    return typeof ack === 'string' && ack !== '' ? ack : null
  })
}

/**
 * The impersonating admin's display name; empty unless the session is being
 * impersonated. Derived from the impersonatedBy slot the same way the current
 * user's name is, so a project never restates the selector.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Impersonated-by slot and name-field overrides.
 */
export function sessionImpersonatedByName(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<string> {
  const slot = options.impersonatedBySlot ?? DEFAULT_IMPERSONATED_BY_SLOT
  const field = options.currentUserNameField ?? DEFAULT_CURRENT_USER_NAME_FIELD
  const impersonatedByRef = scopes.session.data.signal(slot) as ReadonlySignal<
    EntityRef | undefined
  >

  return computedSignal(() => {
    const ref = impersonatedByRef.get()
    if (!ref) {
      return ''
    }
    const snapshot = scopes.entitySignal(ref).get()

    return snapshot ? readString(snapshot.fields, field) : ''
  })
}
