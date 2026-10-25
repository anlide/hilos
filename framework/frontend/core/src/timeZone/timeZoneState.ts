// The reader's zone of one tab: the person's own choice, this browser's zone and
// the project's zone, held as one snapshot and projected into three read-only
// signals. Module memory is this tab's projection, not a second durable source:
// the person's choice lives on their account row and reaches every tab through
// the session response, and the browser's zone belongs to this browser alone, so
// nothing here is replicated back to the server (Hilos::$rt was weighed and not
// chosen; docs/agents/framework-first.md, runtime/rt-context.md, truth-source.md).
import { z } from 'zod'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { entityFragmentSchema } from '../protocol/scopePayload.js'
import {
  SIGNAL_HANDSHAKE_RESPONSE,
  type SessionScopeOptions,
} from '../session/sessionScope.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import { readBrowserTimeZone } from './timeZoneBrowser.js'
import { resolveTimeZone, usableTimeZone } from './timeZoneRule.js'

/** The slot `bindSessionScope` resolves the current user under by default. */
const DEFAULT_CURRENT_USER_SLOT = 'currentUser'
const sessionEntitiesSchema = z.object({
  entities: z.record(z.string(), z.unknown()),
})
const currentUserSchema = entityFragmentSchema.nullable()

/** The three inputs of the reader's zone, replaced whole and never mutated. */
interface TimeZoneSnapshot {
  readonly ownTimeZone: string | null
  readonly browserTimeZone: string | null
  readonly projectTimeZone: string | null
}

const UNBOUND: TimeZoneSnapshot = {
  ownTimeZone: null,
  browserTimeZone: null,
  projectTimeZone: null,
}

const snapshotSignal = createSignal<TimeZoneSnapshot>(UNBOUND)

/**
 * The zone this tab's reader reads every moment in: their own, else this
 * browser's, else the project's, else UTC. Always a usable IANA name.
 */
export const hilosTimeZone: ReadonlySignal<string> = computedSignal(() => {
  const snapshot = snapshotSignal.get()

  return resolveTimeZone(
    snapshot.ownTimeZone,
    snapshot.browserTimeZone,
    snapshot.projectTimeZone,
  )
})

/**
 * The person's own usable zone, or null for Automatic, a guest, or a choice
 * this engine cannot use.
 *
 * The profile's Time zone row (HilosProfileRootStore.timeZone) reads this to
 * project the account row; createHilosTimeZonePicker reads it for the live
 * confirmed selection.
 */
export const hilosOwnTimeZone: ReadonlySignal<string | null> = computedSignal(
  () => snapshotSignal.get().ownTimeZone,
)

/**
 * The zone this browser reported at its latest read, or null when it cannot say.
 *
 * The profile's Time zone row (HilosProfileRootStore.timeZone) reads this when
 * projecting the Automatic label; createHilosTimeZonePicker reads it for the
 * automatic option.
 */
export const hilosBrowserTimeZone: ReadonlySignal<string | null> =
  computedSignal(() => snapshotSignal.get().browserTimeZone)

let activeRelease: (() => void) | null = null

/**
 * Publish one input event's whole result, so no reader sees a zone between two
 * of its steps; an unchanged snapshot wakes nobody.
 *
 * @param next The snapshot after the event.
 */
function publish(next: TimeZoneSnapshot): void {
  const current = snapshotSignal.get()
  if (
    next.ownTimeZone !== current.ownTimeZone ||
    next.browserTimeZone !== current.browserTimeZone ||
    next.projectTimeZone !== current.projectTimeZone
  ) {
    snapshotSignal.set(next)
  }
}

/**
 * Read the person's own zone off a validated handshake_response payload.
 *
 * The frame is the source, not the field-wise merged session entity: a page or
 * group may overlay the same person, and an upsert keeps a field the newer
 * response omits. A user without the field (an older server), a null or an
 * unusable zone, and a guest all read as null; a missing or malformed
 * container is not a sign-out and reads as undefined, keeping the last answer.
 *
 * @param data The payload `bindSessionScope` ingests.
 * @param slot The configured current-user slot.
 */
function ownFromResponse(
  data: unknown,
  slot: string,
): string | null | undefined {
  const session = sessionEntitiesSchema.safeParse(data)
  if (!session.success) {
    return undefined
  }
  const user = currentUserSchema.safeParse(session.data.entities[slot])
  if (!user.success) {
    return undefined
  }

  return user.data === null ? null : usableTimeZone(user.data['timeZone'])
}

/**
 * Bind this tab's zone before the socket opens. A second bind releases the
 * first; the returned release is idempotent, cannot undo a newer binding, and
 * leaves the last projection readable until the next bind.
 *
 * The browser's zone is read again on bind, on every welcome and session
 * response, on window focus and when the document becomes visible — no polling,
 * so a hidden tab learns a new system zone when it returns. A disconnect alone
 * keeps the person's choice: identity lives until the next response.
 *
 * @param connection The application's one connection.
 * @param options The current-user slot override; impersonatedBy is never read.
 * @returns A release for this binding's connection and browser listeners.
 */
export function bindTimeZoneState(
  connection: HilosConnection,
  options: SessionScopeOptions = {},
): () => void {
  activeRelease?.()

  const slot = options.currentUserSlot ?? DEFAULT_CURRENT_USER_SLOT
  let bound = true

  const update = (change: Partial<TimeZoneSnapshot>): void => {
    if (bound) {
      publish({
        ...snapshotSignal.get(),
        browserTimeZone: readBrowserTimeZone(),
        ...change,
      })
    }
  }

  publish({
    ownTimeZone: null,
    browserTimeZone: readBrowserTimeZone(),
    projectTimeZone: usableTimeZone(connection.projectTimeZone),
  })

  // The connection stores the welcome's zone before it emits, and clears it when
  // a welcome names none, so the getter is the one project source.
  const stopHandshake = connection.on('handshake', () => {
    update({ projectTimeZone: usableTimeZone(connection.projectTimeZone) })
  })
  const stopSession = connection.on('projectSignal', (signal) => {
    if (signal.type !== SIGNAL_HANDSHAKE_RESPONSE) {
      return
    }
    const own = ownFromResponse(signal.data, slot)
    update(own === undefined ? {} : { ownTimeZone: own })
  })

  const onFocus = (): void => {
    update({})
  }
  if (typeof globalThis.addEventListener === 'function') {
    globalThis.addEventListener('focus', onFocus)
  }
  // A prerender or a test may run with no document, or one without events.
  const page = typeof document === 'undefined' ? null : document
  const onVisibility = (): void => {
    if (page?.visibilityState === 'visible') {
      update({})
    }
  }
  if (typeof page?.addEventListener === 'function') {
    page.addEventListener('visibilitychange', onVisibility)
  }

  const release = (): void => {
    if (!bound) {
      return
    }
    bound = false
    stopHandshake()
    stopSession()
    if (typeof globalThis.removeEventListener === 'function') {
      globalThis.removeEventListener('focus', onFocus)
    }
    if (typeof page?.removeEventListener === 'function') {
      page.removeEventListener('visibilitychange', onVisibility)
    }
    if (activeRelease === release) {
      activeRelease = null
    }
  }
  activeRelease = release

  return release
}
