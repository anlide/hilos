// One fake connection carrying both answers a tab reads with: the person's own
// zone on the session entities and the region's formats on the tabLanguage node,
// each delivered as its own handshake response through the real bindings.
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { HILOS_DEFAULT_READER_FORMATS } from '../../src/format/readerDisplay.js'
import {
  bindTabLanguageState,
  type HilosLocaleFormats,
  type HilosTabLanguage,
} from '../../src/language/tabLanguageState.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../../src/protocol/constants.js'
import { bindTimeZoneState } from '../../src/timeZone/timeZoneState.js'

/** A bound reader: choose a zone, name a region, release both bindings. */
export interface ReaderFixture {
  choose(timeZone: string | null): void
  region(formats: Partial<HilosLocaleFormats> | null): void
  release(): void
}

/**
 * The tab's language node with the given formats over the built-in ones.
 *
 * @param formats The formats that differ from the built-in English.
 */
export function regionNode(
  formats: Partial<HilosLocaleFormats>,
): HilosTabLanguage {
  return {
    languageId: 1,
    languageCode: 'en',
    rtl: false,
    localeId: 2,
    localeCode: 'en-GB',
    formats: { ...HILOS_DEFAULT_READER_FORMATS, ...formats },
  }
}

/** Bind the real zone and region states to one scripted connection. */
export function bindReader(): ReaderFixture {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: (payload: never) => void): () => void {
      const projectListener = listener as (signal: ProjectSignal) => void
      if (event === 'projectSignal') listeners.add(projectListener)

      return () => {
        listeners.delete(projectListener)
      }
    },
  }
  const deliver = (data: unknown): void => {
    for (const listener of listeners) {
      listener({ type: SIGNAL_HANDSHAKE_RESPONSE, data } as ProjectSignal)
    }
  }
  const releaseZone = bindTimeZoneState(
    connection as unknown as HilosConnection,
  )
  const releaseRegion = bindTabLanguageState(
    connection as unknown as HilosConnection,
  )

  return {
    choose(timeZone) {
      deliver({ entities: { currentUser: { id: 1, timeZone } } })
    },
    region(formats) {
      deliver({ data: { tabLanguage: formats && regionNode(formats) } })
    },
    release() {
      releaseRegion()
      releaseZone()
    },
  }
}
