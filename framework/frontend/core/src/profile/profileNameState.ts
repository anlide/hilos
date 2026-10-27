// A tab's held projection of the session holder's complete name-change frame.
// The owner and live operation already live in Hilos::$rt; this memory only
// keeps their browser answer until a view mounts. It has no cross-worker reader
// or write path (framework-first.md, runtime/rt-context.md, truth-source.md).
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  SIGNAL_PROFILE_NAME_STATE,
  type HilosProfileNameState,
} from '../protocol/profileNameStateSignal.js'
import {
  sessionAccountBlocked,
  sessionAccountStanding,
  sessionImpersonating,
  sessionPendingAck,
  sessionPendingAuthStep,
  sessionHandshakeHeard,
  sessionUserId,
  SIGNAL_HANDSHAKE_RESPONSE,
  type SessionScopeOptions,
} from '../session/sessionScope.js'
import {
  clearProfileRenameMemory,
  readProfileRenameMemory,
} from './profileRenameMemory.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'

export {
  profileNameStateSchema,
  SIGNAL_PROFILE_NAME_STATE,
  type HilosProfileNameChange,
  type HilosProfileNameState,
} from '../protocol/profileNameStateSignal.js'

/** Identity knowledge and its answer publish together, without an intermediate owner. */
interface HeldProfileNameState {
  readonly identity: number | null | undefined
  readonly snapshot: HilosProfileNameState | null
  readonly access: boolean
}

const UNKNOWN: HeldProfileNameState = {
  identity: undefined,
  snapshot: null,
  access: false,
}
const held = createSignal<HeldProfileNameState>(UNKNOWN)

/** Last complete answer for the authoritative person; null means unknown. */
export const hilosProfileNameState: ReadonlySignal<HilosProfileNameState | null> =
  computedSignal(() => held.get().snapshot)

/** Whether STATE answered, including a known `change: null`, for this socket's person. */
export const hilosProfileNameStateKnown: ReadonlySignal<boolean> =
  computedSignal(() => held.get().snapshot !== null)

/**
 * Authoritative session person, null after sign-out, undefined before knowledge.
 */
export const hilosProfileNameIdentity: ReadonlySignal<
  number | null | undefined
> = computedSignal(() => held.get().identity)

/** Whether the complete session response permits the name window. */
export const hilosProfileNameAccess: ReadonlySignal<boolean> = computedSignal(
  () => held.get().access,
)

let heldConnection: HilosConnection | null = null
let activeRelease: (() => void) | null = null

/**
 * Hold STATE before the socket opens, after `bindSessionScope` is registered.
 * Identity is read through its selectors after the whole response was ingested,
 * rather than reacting to intermediate fields of that synchronous ingest.
 * Initial scope nulls do not mean sign-out. Frames preceding their identity wait
 * for its response; a different connection starts unknown. Rebinding the same
 * connection keeps its answer until authoritative knowledge replaces it.
 *
 * @param connection The application's one connection.
 * @param scopes The session stores already bound to that connection.
 * @param options The same current-user overrides passed to `bindSessionScope`.
 * @returns Idempotent release; a replaced binding cannot release its successor.
 */
export function bindProfileNameState(
  connection: HilosConnection,
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): () => void {
  activeRelease?.()
  if (heldConnection !== connection) {
    heldConnection = connection
    held.set(UNKNOWN)
  }

  const userId = sessionUserId(scopes, options)
  const standing = sessionAccountStanding(scopes)
  const blocked = sessionAccountBlocked(scopes)
  const impersonating = sessionImpersonating(scopes, options)
  const ack = sessionPendingAck(scopes, options)
  const authStep = sessionPendingAuthStep(scopes)
  let authoritative = sessionHandshakeHeard(scopes).get()
  let pending: HilosProfileNameState | null = null
  let bound = true

  const applyIdentity = (): void => {
    const identity = userId.get()
    const snapshot = pending ?? held.get().snapshot
    pending = null
    const account = standing.get()
    const access =
      identity !== null &&
      blocked.get() === null &&
      !account?.blocked &&
      !account?.frozen &&
      account?.mergedInto == null &&
      !impersonating.get() &&
      ack.get() === null &&
      authStep.get() === null
    const memory = readProfileRenameMemory()
    if (memory !== null && (memory.userId !== identity || !access)) {
      clearProfileRenameMemory()
    }
    held.set({
      identity,
      access,
      snapshot: snapshot?.userId === identity ? snapshot : null,
    })
  }
  if (authoritative) {
    applyIdentity()
  }

  const stopWelcome = connection.on('handshake', () => {
    if (!bound) return
    authoritative = false
    pending = null
    // A new socket may name a new session even for the same person. Wait for its
    // fresh STATE, but do not announce a sign-out to future tab memory.
    held.set({ ...held.get(), snapshot: null })
  })
  const stopFrames = connection.on('projectSignal', (signal) => {
    if (!bound) return
    if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
      authoritative = true
      applyIdentity()
    }
    if (signal.type === SIGNAL_PROFILE_NAME_STATE) {
      // The canonical connection boundary validated profileNameStateSchema.
      const snapshot = signal.data as HilosProfileNameState
      if (authoritative && snapshot.userId === held.get().identity) {
        pending = null
        held.set({ ...held.get(), snapshot })
      } else {
        pending = snapshot
      }
    }
  })

  const release = (): void => {
    if (!bound) return
    bound = false
    pending = null
    stopWelcome()
    stopFrames()
    if (activeRelease === release) activeRelease = null
  }
  activeRelease = release

  return release
}
