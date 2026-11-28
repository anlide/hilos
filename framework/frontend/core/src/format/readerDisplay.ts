// How this tab reads a moment or a number: the reader's zone and the formats of
// the tab's region, as one signal. The zone decides which hour it is, the
// region decides how to write it down; a screen that draws with the core
// formatters follows this signal to redraw when either changes. Nothing is held
// here: both halves are projections bound elsewhere — the zone by
// bindTimeZoneState, the region by bindTabLanguageState — and this signal only
// combines them (docs/agents/architecture/language-and-region.md).
import {
  hilosTabLanguage,
  type HilosLocaleFormats,
} from '../language/tabLanguageState.js'
import { computedSignal, type ReadonlySignal } from '../state/signal.js'
import { hilosTimeZone } from '../timeZone/timeZoneState.js'

/** How one tab reads: the reader's zone and the formats of the tab's region. */
export interface HilosReaderDisplay {
  readonly timeZone: string
  readonly formats: HilosLocaleFormats
}

/**
 * The formats of the built-in English locale without a country — what a tab
 * writes in before its first handshake answer and in a project without the
 * I18N feature. Never the browser's locale. A copy of the server's built-in
 * catalog row (framework/backend/I18n/Catalog/BuiltInI18nCatalog.php).
 */
export const HILOS_DEFAULT_READER_FORMATS: HilosLocaleFormats = Object.freeze({
  date: 'DD.MM.YYYY',
  time: 'HH:mm:ss',
  number: '1,000.00',
  phone: '+X (XXX) XXX-XXXX',
  address: 'House, Street, State, Index',
  measurement: 'metric',
  collation: 'und',
  firstWeekday: 1,
})

/**
 * The formats of the tab's region read now, or the built-in ones while the tab
 * knows no region — the formats half of hilosReaderDisplay. The core
 * formatters read it on every call without touching the live zone, so a
 * snapshot written in its captured zone never reads the reader's.
 *
 * @returns The formats the tab writes in.
 */
export function hilosReaderFormats(): HilosLocaleFormats {
  return hilosTabLanguage.get()?.formats ?? HILOS_DEFAULT_READER_FORMATS
}

/**
 * How this tab reads right now: the zone of hilosTimeZone and the formats of
 * hilosTabLanguage, or the built-in formats while the tab knows no region.
 * Changes when the zone or the region's formats change.
 */
export const hilosReaderDisplay: ReadonlySignal<HilosReaderDisplay> =
  computedSignal(() => ({
    timeZone: hilosTimeZone.get(),
    formats: hilosReaderFormats(),
  }))
