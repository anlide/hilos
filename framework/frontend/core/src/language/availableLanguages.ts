import { z } from 'zod'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  SIGNAL_TYPE_GROUP_RESPONSE,
  SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR,
} from '../protocol/constants.js'
import {
  type GroupResponse,
  type GroupSubscriptionError,
} from '../protocol/groupError.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../session/sessionScope.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/** The singleton group carrying the languages available to every tab. */
export const LANGUAGES_GROUP = 'hilos_languages'

/** The live replacement for the complete list sent on join. */
export const SIGNAL_LANGUAGES_STATE = 'hilos_languages_state'

export const availableLocaleSchema = z.looseObject({
  id: z.number().int(),
  code: z.string(),
  countryCode: z.string().nullable(),
  countryName: z.string().nullable(),
})

export const availableLanguageSchema = z.looseObject({
  id: z.number().int(),
  code: z.string(),
  nativeName: z.string(),
  locales: z.array(availableLocaleSchema),
})

export const availableLanguagesSchema = z.looseObject({
  languages: z.array(availableLanguageSchema),
})

/** The schema used by the connection to parse live updates. */
export const LANGUAGES_SIGNAL_SCHEMAS = {
  [SIGNAL_LANGUAGES_STATE]: availableLanguagesSchema,
}

export type HilosAvailableLocale = z.infer<typeof availableLocaleSchema>
export type HilosAvailableLanguage = z.infer<typeof availableLanguageSchema>

export interface HilosAvailableLanguagesStore {
  /** The latest complete list, or null until the server has supplied one. */
  readonly languages: ReadonlySignal<readonly HilosAvailableLanguage[] | null>
  ingest(list: readonly HilosAvailableLanguage[]): void
  clear(): void
}

export function createHilosAvailableLanguagesStore(): HilosAvailableLanguagesStore {
  const languages = createSignal<readonly HilosAvailableLanguage[] | null>(null)

  return {
    languages,
    ingest(list) {
      languages.set(list)
    },
    clear() {
      languages.set(null)
    },
  }
}

/** The application-wide list fed by the languages group. */
export const hilosAvailableLanguages = createHilosAvailableLanguagesStore()

/**
 * Follow the languages group for guests and signed-in users. Register before
 * opening the socket so the join can follow its handshake response. Changing
 * the person behind the connection drops its groups (HIL-1284), so join again
 * and keep the shared list until a fresh one arrives.
 *
 * @param connection The application's Hilos connection.
 * @param store The list to feed, usually {@link hilosAvailableLanguages}.
 * @param userId The session's current user id signal.
 */
export function bindAvailableLanguagesScope(
  connection: HilosConnection,
  store: HilosAvailableLanguagesStore,
  userId: ReadonlySignal<number | null>,
): void {
  let sessionAnswered = false
  let joined = false

  function maybeJoin(): void {
    if (connection.state === 'connected' && sessionAnswered && !joined) {
      joined = connection.subscribeToGroup(LANGUAGES_GROUP)
    }
  }

  function ingestJoinAnswer(answer: GroupResponse): void {
    if (!joined || answer.group !== LANGUAGES_GROUP) {
      return
    }
    const snapshot = availableLanguagesSchema.safeParse(answer.payload)
    if (!snapshot.success) {
      console.warn(
        '[hilos] languages group answered with an unreadable snapshot',
        snapshot.error,
      )
      return
    }
    store.ingest(snapshot.data.languages)
  }

  function reportRefusal(refusal: GroupSubscriptionError): void {
    if (refusal.group !== LANGUAGES_GROUP) {
      return
    }
    // The next handshake, identity change, or admission can try again.
    joined = false
    console.warn(
      `[hilos] languages group join refused: ${refusal.errorCode} - ${refusal.message}`,
    )
  }

  connection.on('projectSignal', (signal) => {
    switch (signal.type) {
      case SIGNAL_HANDSHAKE_RESPONSE:
        sessionAnswered = true
        maybeJoin()
        break
      case SIGNAL_TYPE_GROUP_RESPONSE:
        ingestJoinAnswer(signal.data as GroupResponse)
        break
      case SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR:
        reportRefusal(signal.data as GroupSubscriptionError)
        break
      case SIGNAL_LANGUAGES_STATE:
        store.ingest(
          (signal.data as z.infer<typeof availableLanguagesSchema>).languages,
        )
        break
    }
  })

  connection.on('state', (state) => {
    if (state !== 'connected') {
      joined = false
      sessionAnswered = false
    }
  })
  // After a freeze the database may have changed. Joining fetches a fresh list.
  connection.on('protectedMode', (status) => {
    if (status.active) {
      joined = false
      return
    }
    maybeJoin()
  })
  subscribeSignal(userId, () => {
    joined = false
    maybeJoin()
  })
}
