// The language and region one tab is shown in, as the server decided them for
// this connection and told the tab on every handshake response. Module memory is
// this tab's projection, not a second durable source: the server keeps the tab's
// pair on its connection row and restates it in the next response, so nothing
// here is replicated back (Hilos::$rt was weighed and not chosen;
// docs/agents/framework-first.md, docs/agents/architecture/language-and-region.md).
import { z } from 'zod'
import {
  isProjectSignalFromCurrentSocket,
  type HilosConnection,
} from '../connection/HilosConnection.js'
import { applyDocumentLanguage } from '../dom/applyDocumentLanguage.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../session/sessionScope.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'

/** The seven display formats of a tab's region, as the server writes them. */
export interface HilosLocaleFormats {
  readonly date: string
  readonly time: string
  readonly number: string
  readonly phone: string
  readonly address: string
  readonly measurement: 'metric' | 'imperial'
  readonly collation: string
}

/**
 * A tab's active language and region. The region's id and code are null when
 * no stored locale fits; the formats are then the catalog's own.
 */
export interface HilosTabLanguage {
  readonly languageId: number
  readonly languageCode: string
  readonly rtl: boolean
  readonly localeId: number | null
  readonly localeCode: string | null
  readonly formats: HilosLocaleFormats
}

const localeFormatsSchema = z.object({
  date: z.string(),
  time: z.string(),
  number: z.string(),
  phone: z.string(),
  address: z.string(),
  measurement: z.enum(['metric', 'imperial']),
  collation: z.string(),
}) satisfies z.ZodType<HilosLocaleFormats>
const LOCALE_FORMAT_KEYS = localeFormatsSchema.keyof().options
const tabLanguageSchema = z.object({
  languageId: z.int(),
  languageCode: z.string(),
  rtl: z.boolean(),
  localeId: z.int().nullable(),
  localeCode: z.string().nullable(),
  formats: localeFormatsSchema,
}) satisfies z.ZodType<HilosTabLanguage>
// A response without the key or with a malformed node fails this schema and
// keeps the last answer; only a well-formed node or an explicit null replaces it.
const handshakeTabLanguageSchema = z.object({
  data: z.object({ tabLanguage: tabLanguageSchema.nullable() }),
})

const tabLanguageSignal = createSignal<HilosTabLanguage | null>(null)

/**
 * The tab's active language and region, or null before the first response that
 * names them, in a project without the I18N feature, and while the default
 * language has no row yet.
 */
export const hilosTabLanguage: ReadonlySignal<HilosTabLanguage | null> =
  tabLanguageSignal

let activeRelease: (() => void) | null = null

/**
 * Compare two answers field by field: every response parses into a new object,
 * and an unchanged answer must wake nobody.
 *
 * @param left The current answer.
 * @param right The incoming answer.
 */
function sameTabLanguage(
  left: HilosTabLanguage | null,
  right: HilosTabLanguage | null,
): boolean {
  if (left === null || right === null) {
    return left === right
  }

  return (
    left.languageId === right.languageId &&
    left.languageCode === right.languageCode &&
    left.rtl === right.rtl &&
    left.localeId === right.localeId &&
    left.localeCode === right.localeCode &&
    LOCALE_FORMAT_KEYS.every((key) => left.formats[key] === right.formats[key])
  )
}

/**
 * Publish one response's answer. A language names the document's `lang`; null
 * leaves `lang` as it stands, the value the page was served with included.
 *
 * @param next The answer the response carried.
 */
function publish(next: HilosTabLanguage | null): void {
  if (sameTabLanguage(tabLanguageSignal.get(), next)) {
    return
  }
  tabLanguageSignal.set(next)
  if (next !== null) {
    applyDocumentLanguage(next.languageCode)
  }
}

/**
 * Bind this tab's language before the socket opens. The tab knows no language
 * until its connection's first response; every later response from the current
 * socket replaces it at once, with no reload. A second bind releases the first;
 * the returned release is idempotent, cannot undo a newer binding, and leaves
 * the last answer readable until the next bind.
 *
 * @param connection The application's one connection.
 * @returns A release for this binding's connection listener.
 */
export function bindTabLanguageState(connection: HilosConnection): () => void {
  activeRelease?.()

  tabLanguageSignal.set(null)

  const stopSession = connection.on('projectSignal', (signal) => {
    if (
      signal.type !== SIGNAL_HANDSHAKE_RESPONSE ||
      !isProjectSignalFromCurrentSocket(signal)
    ) {
      return
    }
    const response = handshakeTabLanguageSchema.safeParse(signal.data)
    if (response.success) {
      publish(response.data.data.tabLanguage)
    }
  })

  let bound = true
  const release = (): void => {
    if (!bound) {
      return
    }
    bound = false
    stopSession()
    if (activeRelease === release) {
      activeRelease = null
    }
  }
  activeRelease = release

  return release
}
