// Session-scope bootstrap: route the backend's handshake response into the
// session scope and expose the current user reactively. The session-scope
// analog of bindPageScope — lifted from every project so the handshake plumbing
// and the current-user selector live once
// (docs/agents/frontend/bootstrap-structure.md).
import {
  SIGNAL_CODE_SEND_PROGRESS,
  codeSendProgressSchema,
} from '../auth/authSendProgress.js'
import { z } from 'zod'
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
import { applyServerTime, toLocal } from './serverClock.js'
import { SIGNAL_SESSION_TOASTS, sessionToastsSchema } from './sessionToasts.js'

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
 * Plain session-scope key carrying the server's own "now" in epoch milliseconds
 * (HIL-486). Fixed rather than an option: the field is framework-owned and the
 * backend writes it on every handshake, so nothing is left for a project to name.
 */
const SERVER_TIME_MS_KEY = 'serverTimeMs'

/**
 * Plain session-scope key carrying the authentication step this session stands
 * on, or null when it stands on none (HIL-486, HIL-648). Fixed for the same
 * reason as the clock beside it: the backend writes it on every handshake.
 */
const PENDING_AUTH_STEP_KEY = 'pendingAuthStep'

/**
 * Plain session-scope key carrying what this installation can deliver a
 * one-time code to (HIL-830). Fixed for the same reason as the two keys above:
 * the backend writes it on every handshake and no project names it.
 */
const CODE_DELIVERY_KEY = 'codeDelivery'

/**
 * Plain session-scope key carrying the installation's enabled sign-in methods
 * (HIL-427), each with whether the installation can serve it (HIL-1080).
 * Written by every handshake and again by {@link SIGNAL_AUTH_METHODS} whenever
 * the set changes, so one key holds the live answer.
 */
const AUTH_METHODS_KEY = 'authMethods'

/**
 * The settings library, and the OAuth provider page → every connection: the
 * installation's enabled sign-in methods with their readiness, sent after a
 * setting or provider write that changed them (PHP `HILOS_AUTH_METHODS`).
 */
export const SIGNAL_AUTH_METHODS = 'hilos_auth_methods'

/**
 * One enabled sign-in method (HIL-427): its key, and the name a provider's
 * button shows — null for a method that is not a provider, which the surface
 * names itself. The wire entry also says whether the method is ready
 * (HIL-1080); that flag is read when the set is parsed and narrows
 * {@link sessionAuthMethods}, and does not travel further.
 */
export interface AuthMethodEntry {
  /** The method key: `password`, `passkey`, `magic_link`, `sms` or `oauth:<provider>`. */
  readonly key: string
  /** The provider's name for an `oauth:` key, or null. */
  readonly name: string | null
}

const authMethodEntrySchema = z.looseObject({
  key: z.string(),
  name: z.string().nullable(),
  ready: z.boolean().optional(),
})

/** One parsed wire entry: the public entry plus whether the installation can serve it. */
interface AuthMethodWireEntry extends AuthMethodEntry {
  /** False only when the backend said so; an entry without the flag reads as ready. */
  readonly ready: boolean
}

/** The payload of {@link SIGNAL_AUTH_METHODS}: the whole set, in button order. */
export const authMethodsSchema = z.looseObject({
  authMethods: z.array(authMethodEntrySchema),
})

/**
 * What an installation with nothing configured is READ as, key by key. A
 * missing or unreadable answer must not withdraw registration from a working
 * deployment, so the fallback is what every deployment did before the key
 * existed: offer everything, and meet whatever wall there is further in.
 */
const CODE_DELIVERY_FALLBACK: CodeDelivery = { email: true, phone: true }

/**
 * What this installation can deliver a one-time code to (HIL-830). Two answers
 * and not one flag: mail wired with no phone channel, and the reverse, are both
 * ordinary deployments, and a surface told a single boolean would withdraw the
 * path that works along with the one that does not.
 */
export interface CodeDelivery {
  /** Whether a code sent to an email address would reach anybody. */
  readonly email: boolean
  /** Whether a code sent to a phone number would reach anybody. */
  readonly phone: boolean
}

/**
 * The authentication step a session stands on and has not finished, as the
 * handshake reports it (HIL-486, HIL-648). `intent` names which flow it belongs
 * to and `step` which screen of it, so one node describes a registration and a
 * password recovery alike — a session cannot stand in both at once. `channel`
 * names the code channel a phone code went over and is null for a mail flow,
 * which has no choice to name; `expiresAt` arrives as a SERVER moment and is
 * handed on in local ms, like every other moment the backend sends.
 *
 * The node also names WHY the session stands where it does (HIL-833), in the
 * vocabulary the live converge signal already uses. That is what lets the
 * handshake report a step the session was MOVED to rather than only the one it
 * left off on: a browser asleep while somebody else registered the address it
 * was racing for comes back to the identifier field knowing the address is
 * taken, instead of to a code screen for a registration that has quietly
 * stopped being winnable.
 */
export interface PendingAuthStep {
  /** The identifier the code went to, shown on the screen it is restored to. */
  readonly identifier: string
  /** What that identifier is — the classification the backend made of it. */
  readonly kind: 'email' | 'phone'
  /**
   * Which flow the session is standing in. `login` is the one it did not ask
   * for: the address it was registering has an account now, and signing in is
   * the way in that exists.
   */
  readonly intent: 'register' | 'recovery' | 'login'
  /**
   * Which screen of that flow it is standing on. `identifier` is the screen a
   * session is sent BACK to, and the only one of the three that stands on no
   * code.
   */
  readonly step: 'code' | 'set_password' | 'identifier'
  /** The code channel it went over, or `null` for a mail flow. */
  readonly channel: string | null
  /**
   * The LOCAL epoch-ms moment the code stops being good, or `null` on the
   * identifier step, which has no code to outlive.
   */
  readonly expiresAt: number | null
  /**
   * Why the session stands here — an `AuthFlowOutcome::CODE_*` value — or
   * `null` when it simply has not finished what it started.
   */
  readonly code: string | null
}

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
 *
 * The send-progress line rides here rather than with the auth-code bundle even
 * though it is an auth frame (HIL-826). This bundle is merged INSIDE
 * {@link createHilosConnection}, so every project gets it; the auth-code bundle is
 * merged per project, and going that way would leave a demo without the line.
 * What it has in common with the toast stack is what decides it: both are
 * addressed to the SESSION, and a session is something every project carries.
 */
export const SESSION_SIGNAL_SCHEMAS = {
  [SIGNAL_HANDSHAKE_RESPONSE]: scopePayloadSchema,
  [SIGNAL_SESSION_TOASTS]: sessionToastsSchema,
  [SIGNAL_CODE_SEND_PROGRESS]: codeSendProgressSchema,
  [SIGNAL_AUTH_METHODS]: authMethodsSchema,
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
 * The pending-ack kind a handshake_response frame carries, or null when the
 * session owes nothing.
 *
 * Read off the FRAME, not the session scope: the surface and
 * {@link bindSessionScope} listen to the same emitter, and a plan that depends
 * on which listener ran first is a plan that breaks when a project moves a
 * line. Absent, null, a non-string and the empty string all mean the session
 * owes nothing (HIL-955).
 *
 * @param data The `data` of a handshake_response frame, the shape
 *   {@link bindSessionScope} ingests.
 */
export function handshakeResponseAck(data: unknown): string | null {
  if (typeof data !== 'object' || data === null) {
    return null
  }
  const ack = (data as ScopePayloadWire).data?.[DEFAULT_PENDING_ACK_SLOT]

  return typeof ack === 'string' && ack !== '' ? ack : null
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
      // The clock is measured BEFORE anything is published (HIL-486): the values
      // going in are what a countdown reads, and a subscriber that woke on them
      // while the offset still belonged to the previous handshake would draw the
      // old clock's answer.
      const serverTimeMs = payload.data?.[SERVER_TIME_MS_KEY]
      if (typeof serverTimeMs === 'number') {
        applyServerTime(serverTimeMs)
      }
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
    if (signal.type === SIGNAL_AUTH_METHODS) {
      // The same key the handshake writes (HIL-427): a surface reads one slot and
      // cannot tell which of the two brought the set, which is the point.
      const frame = signal.data as z.infer<typeof authMethodsSchema>
      ingest(scopes.session, {
        data: { [AUTH_METHODS_KEY]: frame.authMethods },
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
 * The authentication step this session stands on, or null when it stands on
 * none (HIL-486, HIL-648). The step a reloaded tab, a second tab and another
 * device all come back to: it is answered by the server on every handshake, so
 * nothing about it is remembered in the tab.
 *
 * The moment is converted to the local scale on the way out, so a view compares
 * it with `Date.now()` and never with the server's clock; on the identifier step
 * there is no moment at all, and the field is null.
 *
 * Not only where the session stopped, but where it was MOVED to while nobody was
 * listening (HIL-833) — which is why a surface watches this for CHANGES and not
 * only on mount. A node carrying a reason is news; one without is the screen the
 * session was on all along.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionPendingAuthStep(
  scopes: ScopeManager,
): ReadonlySignal<PendingAuthStep | null> {
  const slot = scopes.session.data.signal(PENDING_AUTH_STEP_KEY)

  return computedSignal(() => readPendingAuthStep(slot.get()))
}

/**
 * Read the unfinished auth-step node the handshake delivered, or null when
 * there is none — and equally when what arrived is not one: a half-written node
 * would restore a code screen naming no address or counting down to nothing,
 * which is worse than the identifier field this falls back to.
 *
 * The moment is judged PER STEP rather than always (HIL-833), because the three
 * steps do not stand on the same thing: a code and a new-password screen are
 * drawn against a deadline and are unreadable without one, while the identifier
 * step a lost race sends a session back to has no code left in play, so a moment
 * there is not missing data but invented data. The reason is judged the same way
 * and for the same sentence: on the two steps a session reached by itself it is
 * optional, and on the one it was MOVED to it is the whole message — a silent
 * jump out of a code screen would take somebody off the screen they were using
 * and say nothing about who took it.
 *
 * @param value The raw session-scope slot.
 */
function readPendingAuthStep(value: unknown): PendingAuthStep | null {
  if (value === null || typeof value !== 'object') {
    return null
  }
  const node = value as Record<string, unknown>
  const identifier = node['identifier']
  const kind = node['kind']
  const intent = node['intent']
  const step = node['step']
  const channel = node['channel'] ?? null
  const expiresAt = node['expiresAt'] ?? null
  const code = node['code'] ?? null
  if (
    typeof identifier !== 'string' ||
    (kind !== 'email' && kind !== 'phone') ||
    (intent !== 'register' && intent !== 'recovery' && intent !== 'login') ||
    (step !== 'code' && step !== 'set_password' && step !== 'identifier') ||
    (channel !== null && typeof channel !== 'string') ||
    (code !== null && typeof code !== 'string')
  ) {
    return null
  }
  if (step === 'identifier') {
    if (expiresAt !== null || code === null) {
      return null
    }
  } else if (typeof expiresAt !== 'number') {
    return null
  }

  return {
    identifier,
    kind,
    intent,
    step,
    channel,
    expiresAt: typeof expiresAt === 'number' ? toLocal(expiresAt) : null,
    code,
  }
}

/**
 * What this installation can deliver a one-time code to (HIL-830). Read before
 * anything is typed, which is the whole point of it riding the handshake: a
 * deployment with nothing to send with declines to offer a registration instead
 * of walking somebody to a code screen that will never fill.
 *
 * Deliberately NOT the null-on-garbage rule {@link sessionPendingAuthStep}
 * takes. A half-written auth step is better dropped, because the fallback is the
 * identifier field the person came from; an unreadable delivery answer falls
 * back the other way, because the cost of the two mistakes is not symmetric —
 * an extra invitation is what every deployment had before this key, and a wrong
 * refusal silently locks registration on a working one.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionCodeDelivery(
  scopes: ScopeManager,
): ReadonlySignal<CodeDelivery> {
  const slot = scopes.session.data.signal(CODE_DELIVERY_KEY)

  return computedSignal(() => readCodeDelivery(slot.get()))
}

/**
 * The sign-in methods the installation OFFERS, in button order (HIL-427): the
 * enabled ones it can also serve (HIL-1080) — a provider without its client
 * pair is left out, so no icon is drawn whose click would be refused. The
 * enabled but unready ones are in {@link sessionEnabledAuthMethods}.
 *
 * Live: the handshake writes the set, and the frame of the settings library or
 * of the provider page rewrites it whenever a switch or a client pair moves it,
 * so a surface built from this reshapes itself without asking. Absent before
 * the handshake, and read as no method at all — a surface is not interactive
 * before its handshake anyway, and inventing a set here would draw buttons the
 * installation may have switched off. A malformed entry is dropped rather than
 * guessed at; an entry that does not say whether it is ready is offered.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionAuthMethods(
  scopes: ScopeManager,
): ReadonlySignal<readonly AuthMethodEntry[]> {
  const slot = scopes.session.data.signal(AUTH_METHODS_KEY)

  return computedSignal(() =>
    readAuthMethods(slot.get())
      .filter((entry) => entry.ready)
      .map(publicEntry),
  )
}

/**
 * Every sign-in method the administrator has on, ready or not, in button order
 * (HIL-1080).
 *
 * The same slot as {@link sessionAuthMethods}, answered without the readiness
 * narrowing: two accessors over one set, because "on" and "offered" are two
 * answers. The switches of the sign-in methods screen read this one — an
 * unready provider an administrator switched on stays on there — and so does
 * a label that names a provider rather than offering it.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionEnabledAuthMethods(
  scopes: ScopeManager,
): ReadonlySignal<readonly AuthMethodEntry[]> {
  const slot = scopes.session.data.signal(AUTH_METHODS_KEY)

  return computedSignal(() => readAuthMethods(slot.get()).map(publicEntry))
}

/**
 * Read the method set the session scope holds, dropping what is not an entry.
 *
 * @param value The raw session-scope slot.
 */
function readAuthMethods(value: unknown): readonly AuthMethodWireEntry[] {
  if (!Array.isArray(value)) {
    return []
  }
  const entries: AuthMethodWireEntry[] = []
  for (const item of value) {
    const parsed = authMethodEntrySchema.safeParse(item)
    if (parsed.success) {
      entries.push({
        key: parsed.data.key,
        name: parsed.data.name,
        ready: parsed.data.ready !== false,
      })
    }
  }

  return entries
}

/**
 * Strip a parsed entry down to what leaves this module.
 *
 * @param entry The parsed wire entry.
 */
function publicEntry(entry: AuthMethodWireEntry): AuthMethodEntry {
  return { key: entry.key, name: entry.name }
}

/**
 * Read the delivery answer the handshake delivered, falling back to "everything
 * is deliverable" for an absent, malformed or half-written node.
 *
 * @param value The raw session-scope slot.
 */
function readCodeDelivery(value: unknown): CodeDelivery {
  if (value === null || typeof value !== 'object') {
    return CODE_DELIVERY_FALLBACK
  }
  const node = value as Record<string, unknown>
  const email = node['email']
  const phone = node['phone']
  if (typeof email !== 'boolean' || typeof phone !== 'boolean') {
    return CODE_DELIVERY_FALLBACK
  }

  return { email, phone }
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
