// Session-scope bootstrap: route the backend's handshake response into the
// session scope and expose the current user reactively. The session-scope
// analog of bindPageScope — lifted from every project so the handshake plumbing
// and the current-user selector live once
// (docs/agents/frontend/bootstrap-structure.md).
import {
  dataExportNodeSchema,
  type DataExportNode,
} from '../profile/dataExport.js'

import {
  SIGNAL_CODE_SEND_PROGRESS,
  codeSendProgressSchema,
} from '../auth/authSendProgress.js'
import {
  SIGNAL_PROFILE_FLOWS,
  profileFlowsSchema,
} from '../profile/profileFlows.js'
import { z } from 'zod'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  legalDocumentSchema,
  type HilosLegalDocumentKey,
} from '../legal/legalAgreements.js'
import {
  scopePayloadSchema,
  type ScopePayloadWire,
} from '../protocol/scopePayload.js'
import { USER_ENTITY_TYPE } from '../state/entity.js'
import { type EntityRef } from '../state/EntityStore.js'
import { readString, readStringOrNull } from '../state/fieldReaders.js'
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
const DEFAULT_CURRENT_USER_ENTITY_TYPE = USER_ENTITY_TYPE
const DEFAULT_CURRENT_USER_NAME_FIELD = 'name'
const DEFAULT_CURRENT_USER_PHOTO_FIELD = 'photo'
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
 * written by every handshake and again by SIGNAL_CODE_DELIVERY whenever a
 * setting moves it; no project names it.
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
 * Plain session-scope key carrying whether a passkey may start an account on an
 * unconfirmed address (HIL-1105). It travels beside {@link AUTH_METHODS_KEY} —
 * in the handshake's data section, which lands in the scope whole, and in the
 * same {@link SIGNAL_AUTH_METHODS} frame — so the two are always read together.
 */
const PASSKEY_ALLOWS_UNPROVEN_KEY = 'passkeyAllowsUnproven'

/**
 * Plain session-scope key carrying the "Access closed" card (HIL-289): the
 * blocked account this browser lost or was refused, or `null` when it holds no
 * card. The backend stamps it on every handshake, so a response carrying `null`
 * takes the card down by overwriting the key — no frame of its own is needed.
 */
const ACCOUNT_BLOCKED_KEY = 'accountBlocked'

/**
 * Plain session-scope key carrying the standing of the person the session acts
 * as (HIL-945) — under a takeover, of the person taken over — or `null` for an
 * anonymous session. The backend stamps it on every handshake, like the card
 * beside it, so a newer handshake replaces it whole.
 */
const ACCOUNT_STANDING_KEY = 'accountStanding'

/**
 * Plain session-scope key carrying the node's admin view mode (HIL-1253);
 * written by every handshake, no live frame — the mode does not change under a
 * living process, and the stand lever's flip reaches a tab on its next
 * handshake.
 */
const ADMIN_VIEW_MODE_KEY = 'adminViewMode'

/**
 * Plain session-scope key carrying the installation's impersonation policy
 * (HIL-1170, HIL-1307): the scope, carried admin rights and installation-wide
 * card guards. Written by every handshake and again by
 * {@link SIGNAL_IMPERSONATION_POLICY} whenever a setting moves.
 */
export const IMPERSONATION_POLICY_KEY = 'impersonationPolicy'

/** Plain session-scope key shared by theme settings from the handshake and live frame (HIL-1428). */
const THEME_SETTINGS_KEY = 'themeSettings'

/**
 * The settings library, and the OAuth provider page → every connection: the
 * installation's enabled sign-in methods with their readiness, and the passkey
 * policy beside them (HIL-1105), sent after a setting or provider write that
 * changed either (PHP `HILOS_AUTH_METHODS`).
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

/**
 * The payload of {@link SIGNAL_AUTH_METHODS}: the whole set, in button order,
 * and the passkey policy (HIL-1105).
 */
export const authMethodsSchema = z.looseObject({
  authMethods: z.array(authMethodEntrySchema),
  passkeyAllowsUnproven: z.boolean().optional(),
})

/**
 * The settings library → every connection: the administrator's second-factor
 * settings, sent after a write that moved them (PHP `HILOS_SECOND_FACTOR_POLICY`,
 * HIL-494). The profile section redraws its bounds and its "required" line, and
 * the code step its trust checkbox, without a reload.
 */
export const SIGNAL_SECOND_FACTOR_POLICY = 'hilos_second_factor_policy'

/**
 * The settings library → every connection: what the installation can deliver a
 * one-time code to, sent after a write that changed it (PHP HILOS_CODE_DELIVERY,
 * HIL-1102).
 */
export const SIGNAL_CODE_DELIVERY = 'hilos_code_delivery'

/**
 * The settings library → every connection: the impersonation policy, sent after
 * a write that moved any impersonation setting (PHP
 * `HILOS_IMPERSONATION_POLICY`, HIL-1170, HIL-1307). Open cards, takeover strips,
 * switched-off buttons and the admin gear redraw without a reload.
 */
export const SIGNAL_IMPERSONATION_POLICY = 'hilos_impersonation_policy'

/** The payload of {@link SIGNAL_IMPERSONATION_POLICY}, the same node the handshake carries. */
export const impersonationPolicySchema = z.looseObject({
  viewOnly: z.boolean(),
  carryAdmin: z.boolean(),
  allowed: z.boolean(),
  accountAccess: z.boolean(),
  blocked: z.boolean(),
  frozen: z.boolean(),
  equal: z.boolean(),
})

/**
 * The installation's impersonation policy (HIL-1170, HIL-1307): whether inside a takeover
 * the administrator only looks, and whether they carry their own admin rights in,
 * plus the installation-wide card guards.
 */
export type ImpersonationPolicy = z.infer<typeof impersonationPolicySchema>

/**
 * Defaults until a valid policy frame or handshake lands (HIL-1307).
 */
export const DEFAULT_IMPERSONATION_POLICY: ImpersonationPolicy = Object.freeze({
  viewOnly: false,
  carryAdmin: false,
  allowed: true,
  accountAccess: false,
  blocked: true,
  frozen: true,
  equal: true,
})

/** The settings library → every connection when either theme setting changes (HIL-1428). */
export const SIGNAL_THEME_SETTINGS = 'hilos_theme_settings'

/** The complete theme-settings pair carried by both the frame and the handshake. */
export const themeSettingsSchema = z.looseObject({
  switchingEnabled: z.boolean(),
  defaultTheme: z.enum(['light', 'dark', 'system']),
})

/** Theme settings as last received from the installation. */
export type ThemeSettings = z.infer<typeof themeSettingsSchema>

/** The complete delivery answer, in the same node the handshake carries. */
export const codeDeliverySchema = z.looseObject({
  codeDelivery: z.looseObject({ email: z.boolean(), phone: z.boolean() }),
})

/**
 * Plain session-scope key the last {@link SIGNAL_SECOND_FACTOR_POLICY} is kept
 * under. Only the frame writes it — the handshake does not carry the policy — so
 * it is null until an administrator changes something while this tab is open,
 * and a reader treats it as news newer than what it was answered before.
 */
const SECOND_FACTOR_POLICY_KEY = 'secondFactorPolicy'

/** The payload of {@link SIGNAL_SECOND_FACTOR_POLICY} (PHP `SecondFactorPolicySignalData`). */
export const secondFactorPolicySchema = z.looseObject({
  required: z.enum(['none', 'admins', 'everyone']),
  trustDays: z.number(),
  backupCodes: z.number(),
  resetWaitDefaultDays: z.number(),
  resetWaitMinDays: z.number(),
  resetWaitMaxDays: z.number(),
})

/**
 * The administrator's second-factor settings as the last policy frame said them
 * (HIL-494): who must use a second factor, the days of trust a browser may be
 * given (0 offers none), the size of a set of backup codes, and the bounds of a
 * person's removal wait.
 */
export type SecondFactorPolicy = z.infer<typeof secondFactorPolicySchema>

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
 *
 * A sign-in held on its second factor (HIL-494) is a step of the same node, and
 * the one that names nobody: the person is known to the server, and the screens
 * ask for nothing about them. So on `second_factor` and `second_factor_setup`
 * the identifier and its kind are null and {@link secondFactor} carries what
 * the code step draws. The field those steps send a session back to, when the
 * wait is let go, names nobody either — that is how it is told apart from the
 * field a lost race sends a session to.
 */
export interface PendingAuthStep {
  /**
   * The identifier the code went to, shown on the screen it is restored to, or
   * `null` on the second-factor steps and on the field their wait sends a
   * session back to.
   */
  readonly identifier: string | null
  /**
   * What that identifier is — the classification the backend made of it — or
   * `null` wherever the identifier is.
   */
  readonly kind: 'email' | 'phone' | null
  /**
   * Which flow the session is standing in. `login` is the one it did not ask
   * for: the address it was registering has an account now, and signing in is
   * the way in that exists.
   */
  readonly intent: 'register' | 'recovery' | 'login'
  /**
   * Which screen of that flow it is standing on. `identifier` is the screen a
   * session is sent BACK to, and the only one that stands on no code.
   * `second_factor` asks the code of a sign-in held on its factor, and
   * `second_factor_setup` the enrolment an administrator requires on the way in.
   */
  readonly step:
    | 'code'
    | 'set_password'
    | 'identifier'
    | 'second_factor'
    | 'second_factor_setup'
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
  /**
   * What the second-factor steps draw (HIL-494) — the days of trust the code
   * step offers and the LOCAL moment an already requested removal takes effect —
   * or `null` on every other step.
   */
  readonly secondFactor: PendingSecondFactor | null
  /** Accepted revisions on a live registration's code or password step. */
  readonly acceptedRevisions: Readonly<Record<string, string>> | null
}

/** The "Access closed" card a session holds after its account was blocked (HIL-289). */
export interface AccountBlockedNotice {
  /** The account's confirmed address, or `null` when it had none the server could name. */
  readonly identifier: string | null
  /** Archive node carried with the card, still in server time. */
  readonly dataExport: DataExportNode | null
}

/**
 * The one fact of an account's standing that is shown (HIL-945), the one that
 * takes the most away: blocked, then frozen, then a scheduled deletion. Byte-equal
 * to the backend `AccountStandingKind`.
 */
export type HilosAccountStandingKind =
  | 'none'
  | 'blocked'
  | 'frozen'
  | 'deletion_scheduled'

/**
 * One legal document with an outstanding acceptance deadline: past it in
 * `lapsed` (HIL-945), still ahead of it in `window` (HIL-500).
 */
export interface HilosAccountStandingLapse {
  /** The document the person has not accepted yet. */
  readonly document: HilosLegalDocumentKey
  /** The calendar day the deadline falls on, `YYYY-MM-DD`. */
  readonly deadline: string
}

/**
 * An account's standing (HIL-945): three independent facts and the one of them
 * shown. Composed in one place on the backend and read in the same shape by the
 * shell (the session's own), the admin card and its live frame.
 */
export interface HilosAccountStanding {
  /** The one fact shown, the one that takes the most away. */
  readonly shown: HilosAccountStandingKind
  /** Whether an administrator blocked the account. */
  readonly blocked: boolean
  /** Whether a lapsed acceptance freezes the account under the refusal setting. */
  readonly frozen: boolean
  /** The LOCAL epoch-ms moment the scheduled erasure falls due, or `null` when none is scheduled. */
  readonly deletionEffectiveAt: number | null
  /** Documents whose deadline has passed, whatever the refusal setting says. */
  readonly lapsed: readonly HilosAccountStandingLapse[]
  /**
   * Documents whose deadline is still ahead, whatever the refusal setting says
   * (HIL-500). They take nothing away; the "the terms have changed" window and
   * its reminder in the header are drawn from them.
   */
  readonly window: readonly HilosAccountStandingLapse[]
}

/**
 * The standing as the wire carries it (PHP `AccountStanding::toArray()`); the
 * erasure moment is SERVER epoch ms here. The lapsed documents and those inside
 * their window are read one by one ({@link readHilosAccountStanding}), so a
 * document this client does not know drops out alone instead of taking the whole
 * standing with it.
 */
export const accountStandingSchema = z.looseObject({
  shown: z.enum(['none', 'blocked', 'frozen', 'deletion_scheduled']),
  blocked: z.boolean(),
  frozen: z.boolean(),
  deletionEffectiveAt: z.number().nullable(),
  lapsed: z.array(z.unknown()),
  window: z.array(z.unknown()),
})

/** One lapsed document, or one inside its window, as the wire carries it. */
const accountStandingLapseSchema = z.looseObject({
  document: legalDocumentSchema,
  deadline: z.string(),
})

/** What a sign-in held on its second factor carries into the step it is restored to (HIL-494). */
export interface PendingSecondFactor {
  /** Days "don't ask again on this device" promises, or `null` when no trust is offered. */
  readonly trustDeviceDays: number | null
  /** The LOCAL epoch-ms moment a requested removal takes effect, or `null` when none is. */
  readonly resetEffectiveAt: number | null
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
 * The profile windows' list rides here for the same reason (HIL-1182).
 */
export const SESSION_SIGNAL_SCHEMAS = {
  [SIGNAL_HANDSHAKE_RESPONSE]: scopePayloadSchema,
  [SIGNAL_SESSION_TOASTS]: sessionToastsSchema,
  [SIGNAL_CODE_SEND_PROGRESS]: codeSendProgressSchema,
  [SIGNAL_PROFILE_FLOWS]: profileFlowsSchema,
  [SIGNAL_AUTH_METHODS]: authMethodsSchema,
  [SIGNAL_SECOND_FACTOR_POLICY]: secondFactorPolicySchema,
  [SIGNAL_CODE_DELIVERY]: codeDeliverySchema,
  [SIGNAL_IMPERSONATION_POLICY]: impersonationPolicySchema,
  [SIGNAL_THEME_SETTINGS]: themeSettingsSchema,
}

/** Where the current user sits in the session scope, and which field names it. */
export interface SessionScopeOptions {
  /** Session-scope slot the current user arrives under. Default `currentUser`. */
  currentUserSlot?: string
  /** Canonical entity type for that slot. Default `user`. */
  currentUserEntityType?: string
  /** Entity field holding the display name. Default `name`. */
  currentUserNameField?: string
  /** Entity field holding the published photo URL. Default `photo`. */
  currentUserPhotoField?: string
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
      const plainData = { ...(payload.data ?? {}) }
      if (IMPERSONATION_POLICY_KEY in plainData) {
        const parsedPolicy = impersonationPolicySchema.safeParse(
          plainData[IMPERSONATION_POLICY_KEY],
        )
        if (parsedPolicy.success) {
          plainData[IMPERSONATION_POLICY_KEY] = parsedPolicy.data
        } else {
          delete plainData[IMPERSONATION_POLICY_KEY]
        }
      }
      ingest(scopes.session, { data: plainData })
      ingest(
        scopes.session,
        { ...payload, data: plainData },
        {
          entityTypes: { [slot]: entityType, [impersonatedBySlot]: entityType },
        },
      )
    }
    if (signal.type === SIGNAL_AUTH_METHODS) {
      // The same keys the handshake writes (HIL-427, HIL-1105): a surface reads
      // one slot and cannot tell which of the two brought the set, which is the
      // point. Both keys in one ingest, so no reader sees the set of one frame
      // beside the policy of another.
      const frame = signal.data as z.infer<typeof authMethodsSchema>
      ingest(scopes.session, {
        data: {
          [AUTH_METHODS_KEY]: frame.authMethods,
          [PASSKEY_ALLOWS_UNPROVEN_KEY]: frame.passkeyAllowsUnproven === true,
        },
      })
    }
    if (signal.type === SIGNAL_CODE_DELIVERY) {
      // The handshake and the live frame share one slot (HIL-1102).
      const frame = signal.data as z.infer<typeof codeDeliverySchema>
      ingest(scopes.session, {
        data: { [CODE_DELIVERY_KEY]: frame.codeDelivery },
      })
    }
    if (signal.type === SIGNAL_SECOND_FACTOR_POLICY) {
      ingest(scopes.session, {
        data: { [SECOND_FACTOR_POLICY_KEY]: signal.data },
      })
    }
    if (signal.type === SIGNAL_IMPERSONATION_POLICY) {
      // The handshake and the live frame share one slot (HIL-1170, HIL-1307).
      // A malformed frame does not overwrite the active value.
      const parsed = impersonationPolicySchema.safeParse(signal.data)
      if (parsed.success) {
        ingest(scopes.session, {
          data: { [IMPERSONATION_POLICY_KEY]: parsed.data },
        })
      }
    }
    if (signal.type === SIGNAL_THEME_SETTINGS) {
      ingest(scopes.session, {
        data: { [THEME_SETTINGS_KEY]: signal.data },
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
 * The current user's published photo URL, or null before the handshake or without a photo.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Current-user slot and photo-field overrides.
 */
export function sessionUserPhoto(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): ReadonlySignal<string | null> {
  const slot = options.currentUserSlot ?? DEFAULT_CURRENT_USER_SLOT
  const field =
    options.currentUserPhotoField ?? DEFAULT_CURRENT_USER_PHOTO_FIELD
  const currentUserRef = scopes.session.data.signal(slot) as ReadonlySignal<
    EntityRef | undefined
  >

  return computedSignal(() => {
    const ref = currentUserRef.get()
    if (!ref) return null
    const snapshot = scopes.entitySignal(ref).get()

    return snapshot ? readStringOrNull(snapshot.fields, field) : null
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
 * The moment is judged PER STEP rather than always (HIL-833), because the
 * steps do not stand on the same thing: a code and a new-password screen are
 * drawn against a deadline and are unreadable without one, while the identifier
 * step a lost race sends a session back to has no code left in play, so a moment
 * there is not missing data but invented data. The reason is judged the same way
 * and for the same sentence: on the steps a session reached by itself it is
 * optional, and on the one it was MOVED to it is the whole message — a silent
 * jump out of a code screen would take somebody off the screen they were using
 * and say nothing about who took it.
 *
 * The second factor's nodes (HIL-494) are judged by their own shape: the two
 * steps of the wait name nobody and stand on a deadline and on their data, and
 * the field the wait is let go to names nobody and may name no reason — the
 * person stepped back from it in another tab, or the factor was switched off,
 * and neither is news that needs a sentence.
 *
 * @param value The raw session-scope slot.
 */
function readPendingAuthStep(value: unknown): PendingAuthStep | null {
  if (value === null || typeof value !== 'object') {
    return null
  }
  const node = value as Record<string, unknown>
  const identifier = node['identifier'] ?? null
  const kind = node['kind'] ?? null
  const intent = node['intent']
  const step = node['step']
  const channel = node['channel'] ?? null
  const expiresAt = node['expiresAt'] ?? null
  const code = node['code'] ?? null
  if (
    (intent !== 'register' && intent !== 'recovery' && intent !== 'login') ||
    (channel !== null && typeof channel !== 'string') ||
    (code !== null && typeof code !== 'string')
  ) {
    return null
  }
  if (step === 'second_factor' || step === 'second_factor_setup') {
    const secondFactor = readPendingSecondFactor(node['secondFactor'])
    if (
      identifier !== null ||
      kind !== null ||
      typeof expiresAt !== 'number' ||
      secondFactor === null
    ) {
      return null
    }

    return {
      identifier: null,
      kind: null,
      intent,
      step,
      channel,
      expiresAt: toLocal(expiresAt),
      code,
      secondFactor,
      acceptedRevisions: null,
    }
  }
  if (step === 'identifier' && identifier === null && kind === null) {
    if (expiresAt !== null) {
      return null
    }

    return {
      identifier: null,
      kind: null,
      intent,
      step,
      channel,
      expiresAt: null,
      code,
      secondFactor: null,
      acceptedRevisions: null,
    }
  }
  if (
    typeof identifier !== 'string' ||
    (kind !== 'email' && kind !== 'phone') ||
    (step !== 'code' && step !== 'set_password' && step !== 'identifier')
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

  const acceptedRevisions =
    intent === 'register' && step !== 'identifier'
      ? (node['acceptedRevisions'] ?? null)
      : null
  if (
    acceptedRevisions !== null &&
    (typeof acceptedRevisions !== 'object' ||
      Array.isArray(acceptedRevisions) ||
      Object.values(acceptedRevisions).some(
        (revision) => typeof revision !== 'string' || revision === '',
      ))
  ) {
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
    secondFactor: null,
    acceptedRevisions: acceptedRevisions as Readonly<
      Record<string, string>
    > | null,
  }
}

/**
 * Read what a second-factor step carries, or null when what arrived is not it.
 * Both members are required and both may be null — each null is an answer
 * ("no trust offered", "no removal asked"), not a gap.
 *
 * @param value The raw `secondFactor` member of the step node.
 */
function readPendingSecondFactor(value: unknown): PendingSecondFactor | null {
  if (value === null || typeof value !== 'object') {
    return null
  }
  const node = value as Record<string, unknown>
  const trustDeviceDays = node['trustDeviceDays']
  const resetEffectiveAt = node['resetEffectiveAt']
  if (
    (trustDeviceDays !== null && typeof trustDeviceDays !== 'number') ||
    (resetEffectiveAt !== null && typeof resetEffectiveAt !== 'number')
  ) {
    return null
  }

  return {
    trustDeviceDays,
    resetEffectiveAt:
      resetEffectiveAt === null ? null : toLocal(resetEffectiveAt),
  }
}

/**
 * What this installation can deliver a one-time code to (HIL-830). Read before
 * anything is typed, which is the whole point of it riding the handshake: a
 * deployment with nothing to send with declines to offer a registration instead
 * of walking somebody to a code screen that will never fill. Every handshake
 * writes it, and SIGNAL_CODE_DELIVERY rewrites it when a setting moves it.
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
 * The administrator's second-factor settings as the last policy frame of this
 * tab said them, or `null` when none has arrived since it opened (HIL-494).
 *
 * News, not the whole truth: the handshake does not carry the policy, and what
 * a surface was answered — the section of the profile, the step of a held
 * sign-in — was computed under the policy of its moment. A reader lets this
 * override its answer only when the frame is NEWER than the answer, which it
 * tells by the value itself: every frame is a new object.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionSecondFactorPolicy(
  scopes: ScopeManager,
): ReadonlySignal<SecondFactorPolicy | null> {
  const slot = scopes.session.data.signal(SECOND_FACTOR_POLICY_KEY)

  return computedSignal(() => {
    const parsed = secondFactorPolicySchema.safeParse(slot.get())

    return parsed.success ? parsed.data : null
  })
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
 * Whether a passkey may start an account on an unconfirmed address (HIL-1105).
 *
 * Live beside {@link sessionEnabledAuthMethods}: the handshake writes it and
 * the method-set frame rewrites it. True only when the backend said so —
 * absent before the handshake, missing or malformed reads as no, the answer
 * the backend policy itself gives when it is not told: a wrong no only asks
 * for a confirmed address first, while a wrong yes would offer a way in the
 * installation refuses.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionPasskeyAllowsUnproven(
  scopes: ScopeManager,
): ReadonlySignal<boolean> {
  const slot = scopes.session.data.signal(PASSKEY_ALLOWS_UNPROVEN_KEY)

  return computedSignal(() => slot.get() === true)
}

/**
 * Whether the node this session talks to is in the admin view mode (HIL-1253).
 *
 * True only when the last handshake said true — absent before the handshake,
 * null, missing or anything else reads as off, the same fail-closed default the
 * admin flag takes: an unknown mode draws no way into the admin section. This
 * is the node's mode, not "this session is a viewer": who is a viewer is
 * derived from it and the admin flag together by `hilosAdminAccess`.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionAdminViewMode(
  scopes: ScopeManager,
): ReadonlySignal<boolean> {
  const slot = scopes.session.data.signal(ADMIN_VIEW_MODE_KEY)

  return computedSignal(() => slot.get() === true)
}

/**
 * The installation's impersonation policy as the last valid handshake or policy
 * frame said it (HIL-1170, HIL-1307).
 *
 * Absent, null or unreadable reads as the installation defaults.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionImpersonationPolicy(
  scopes: ScopeManager,
): ReadonlySignal<ImpersonationPolicy> {
  const slot = scopes.session.data.signal(IMPERSONATION_POLICY_KEY)

  return computedSignal(() => {
    const parsed = impersonationPolicySchema.safeParse(slot.get())

    return parsed.success
      ? {
          viewOnly: parsed.data.viewOnly,
          carryAdmin: parsed.data.carryAdmin,
          allowed: parsed.data.allowed,
          accountAccess: parsed.data.accountAccess,
          blocked: parsed.data.blocked,
          frozen: parsed.data.frozen,
          equal: parsed.data.equal,
        }
      : DEFAULT_IMPERSONATION_POLICY
  })
}

/**
 * The installation's theme settings as the last handshake or live frame said them (HIL-1428).
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionThemeSettings(
  scopes: ScopeManager,
): ReadonlySignal<ThemeSettings> {
  const slot = scopes.session.data.signal(THEME_SETTINGS_KEY)

  return computedSignal(() => {
    const parsed = themeSettingsSchema.safeParse(slot.get())

    return parsed.success
      ? {
          switchingEnabled: parsed.data.switchingEnabled,
          defaultTheme: parsed.data.defaultTheme,
        }
      : { switchingEnabled: true, defaultTheme: 'system' }
  })
}

/**
 * The "Access closed" card this session holds, or `null` when it holds none (HIL-289).
 *
 * Live: every handshake writes the key, a card and its removal alike. An object
 * is a card whatever else it carries — a blocked account with no address is
 * still blocked — and its address is kept only as a non-empty string; anything
 * that is not an object reads as no card.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionAccountBlocked(
  scopes: ScopeManager,
): ReadonlySignal<AccountBlockedNotice | null> {
  const slot = scopes.session.data.signal(ACCOUNT_BLOCKED_KEY)

  return computedSignal(() => {
    const value = slot.get()
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
      return null
    }
    const identifier = (value as { identifier?: unknown }).identifier
    const dataExport = dataExportNodeSchema
      .nullable()
      .safeParse((value as { dataExport?: unknown }).dataExport)

    return {
      identifier:
        typeof identifier === 'string' && identifier !== '' ? identifier : null,
      dataExport: dataExport.success ? dataExport.data : null,
    }
  })
}

/**
 * Read a standing as the wire carried it — the session's own, the admin card's
 * page data or its live frame — or `null` when what arrived is not one.
 *
 * `null` is also what an anonymous session carries, and what a viewer of the
 * admin view mode reads where the backend put a hidden mark in the standing's
 * place: an unreadable standing is an absent one. The erasure moment comes back
 * on the LOCAL clock.
 *
 * @param raw The standing as the wire carried it.
 */
export function readHilosAccountStanding(
  raw: unknown,
): HilosAccountStanding | null {
  const parsed = accountStandingSchema.safeParse(raw)
  if (!parsed.success) {
    return null
  }
  const deletionEffectiveAt = parsed.data.deletionEffectiveAt

  return {
    shown: parsed.data.shown,
    blocked: parsed.data.blocked,
    frozen: parsed.data.frozen,
    deletionEffectiveAt:
      deletionEffectiveAt === null ? null : toLocal(deletionEffectiveAt),
    lapsed: readStandingDocuments(parsed.data.lapsed),
    window: readStandingDocuments(parsed.data.window),
  }
}

/**
 * Read one list of documents of a standing entry by entry, dropping what this
 * client cannot read.
 *
 * @param entries The list as the wire carried it.
 */
function readStandingDocuments(
  entries: readonly unknown[],
): HilosAccountStandingLapse[] {
  const documents: HilosAccountStandingLapse[] = []
  for (const entry of entries) {
    const document = accountStandingLapseSchema.safeParse(entry)
    if (document.success) {
      documents.push({
        document: document.data.document,
        deadline: document.data.deadline,
      })
    }
  }

  return documents
}

/**
 * Whether this tab has heard a handshake yet (HIL-500): false until the first
 * response lands, true from then on, whoever it named. The backend stamps the
 * standing on every handshake - `null` for an anonymous session - so the key
 * standing in the scope at all is the mark of a frame heard.
 *
 * The re-consent window reads it to tell a sign-in in this tab from the first
 * frame of a tab opened on a live sign-in: both move the session from nobody to
 * a person, and only the first is an entrance.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionHandshakeHeard(
  scopes: ScopeManager,
): ReadonlySignal<boolean> {
  const slot = scopes.session.data.signal(ACCOUNT_STANDING_KEY)

  return computedSignal(() => slot.get() !== undefined)
}

/**
 * The standing of the person this session acts as (HIL-945), or `null` for an
 * anonymous session.
 *
 * Under a takeover it is the standing of the person taken over: the session
 * carries their identity, so the shell speaks about them. Live: every handshake
 * writes the key.
 *
 * @param scopes The application's scope-partitioned stores.
 */
export function sessionAccountStanding(
  scopes: ScopeManager,
): ReadonlySignal<HilosAccountStanding | null> {
  const slot = scopes.session.data.signal(ACCOUNT_STANDING_KEY)

  return computedSignal(() => readHilosAccountStanding(slot.get()))
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
