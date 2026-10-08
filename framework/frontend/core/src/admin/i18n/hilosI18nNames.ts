// The names tables of the i18n section (HIL-1477): one table on two places — how a
// language is called in every other language, and how a country is called in every
// language. A row is a language of the system; it carries the base name written in
// it, or nothing, and under the chevron the corrections of that language's locales
// of a country. The server windows the table per subject — the language or country
// the page's address names — so the subject travels as a preset of the filter map
// and is set again when the address changes; nothing is filtered on the client.
//
// Framework-agnostic: the views render from what this module resolves, and a row
// whose panel would be empty (no base name, or no locale of a country) has nothing
// to expand, which the controller is told here once for every view layer.
import { z } from 'zod'
import { HilosPages, type HilosPageKey } from '../../routing/hilosPages.js'
import { subscribeSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'

/** Table key of the names of one language, as the backend registers it. */
export const HILOS_I18N_LANGUAGE_NAMES_TABLE = 'hilosI18nLanguageNames'

/** Table key of the names of one country, as the backend registers it. */
export const HILOS_I18N_COUNTRY_NAMES_TABLE = 'hilosI18nCountryNames'

/** Filter key carrying the code of the language the names table is opened on. */
export const HILOS_I18N_LANGUAGE_NAMES_FILTER = 'language'

/** Filter key carrying the code of the country the names table is opened on. */
export const HILOS_I18N_COUNTRY_NAMES_FILTER = 'country'

/** The row slot a names row rides under (mirrors the backend ROW_SLOT). */
const NAMES_SLOT = 'language'

/** Payload keys of a names row (mirrors the backend HilosI18nNameTableRow). */
export const HilosI18nNameRowKey = {
  code: 'code',
  nativeName: 'nativeName',
  name: 'name',
  corrections: 'corrections',
} as const

/** Payload keys of one locale correction inside a names row. */
export const HilosI18nNameCorrectionKey = {
  localeCode: 'localeCode',
  countryCode: 'countryCode',
  countryName: 'countryName',
  name: 'name',
} as const

const correctionSchema = z.object({
  [HilosI18nNameCorrectionKey.localeCode]: z.string(),
  [HilosI18nNameCorrectionKey.countryCode]: z.string(),
  [HilosI18nNameCorrectionKey.countryName]: z.string().nullable(),
  [HilosI18nNameCorrectionKey.name]: z.string().nullable(),
})

const nameSlotSchema = z.object({
  [HilosI18nNameRowKey.code]: z.string(),
  [HilosI18nNameRowKey.nativeName]: z.string(),
  [HilosI18nNameRowKey.name]: z.string().nullable(),
  [HilosI18nNameRowKey.corrections]: z.array(correctionSchema),
})

/** One locale correction of a base name. */
export interface HilosI18nNameCorrection {
  /** Code of the locale, e.g. `en-GB`. */
  readonly localeCode: string
  /** Code of the locale's country, as stored (the view writes it in capitals). */
  readonly countryCode: string
  /** The country's name in the default language, or null when it has none. */
  readonly countryName: string | null
  /** The correction, '' for a stored empty one, or null when the locale inherits the base. */
  readonly name: string | null
}

/** One row of a names table — a language of the system. */
export interface HilosI18nNameRow {
  /** Code of the row's language; also the row key. */
  readonly code: string
  /** The name the row's language calls itself. */
  readonly nativeName: string
  /** The base name written in the row's language, '' for an empty one, null for none. */
  readonly name: string | null
  /** Corrections by locale code; empty when there is no base name. */
  readonly corrections: readonly HilosI18nNameCorrection[]
}

/**
 * Resolve one raw names row into its view-model. A slot the schema refuses —
 * which only a server of another version can send — shows the language by its key
 * with nothing written, rather than dropping the row.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosI18nNameRow(row: TableRow): HilosI18nNameRow {
  const parsed = nameSlotSchema.safeParse(row.slots[NAMES_SLOT])
  if (parsed.success) {
    return parsed.data
  }
  const code = String(row.rowKey)

  return { code, nativeName: code, name: null, corrections: [] }
}

/** Whether a names row has corrections to show under its chevron. */
export function hilosI18nNameRowExpandable(row: HilosI18nNameRow): boolean {
  return row.corrections.length > 0
}

/** The columns of a names table, in display order. */
export const HILOS_I18N_NAMES_COLUMNS: readonly HilosTableColumnOf<HilosI18nNameRow>[] =
  [
    {
      key: HilosI18nNameRowKey.code,
      label: 'Language',
      card: 'title',
      reads: [HilosI18nNameRowKey.nativeName],
    },
    { key: HilosI18nNameRowKey.name, label: 'Name' },
    {
      key: HilosI18nNameRowKey.corrections,
      label: 'Locale corrections',
      detail: true,
      detailWide: true,
      // An inherited correction is spoken as "as in <native name of the row's language>".
      reads: [HilosI18nNameRowKey.nativeName],
    },
  ]

/** Where one names table lives and what it says when it has no rows. */
export interface HilosI18nNamesTarget {
  /** The page the table is bound to. */
  readonly page: HilosPageKey
  /** The table key the backend registers the table under. */
  readonly tableKey: string
  /** The filter key the subject's code travels under. */
  readonly filterKey: string
  /** The frame the table declares: the names columns and its own empty state. */
  readonly frame: HilosTableFrame
}

/** The names of one language: a row for every other language. */
export const HILOS_I18N_LANGUAGE_NAMES: HilosI18nNamesTarget = {
  page: HilosPages.I18N_LANGUAGE_NAMES,
  tableKey: HILOS_I18N_LANGUAGE_NAMES_TABLE,
  filterKey: HILOS_I18N_LANGUAGE_NAMES_FILTER,
  frame: {
    columns: HILOS_I18N_NAMES_COLUMNS,
    empty: {
      title: 'No other languages yet',
      hint: 'A name in another language can be written once that language is added.',
    },
  },
}

/** The names of one country: a row for every language. */
export const HILOS_I18N_COUNTRY_NAMES: HilosI18nNamesTarget = {
  page: HilosPages.I18N_COUNTRY_NAMES,
  tableKey: HILOS_I18N_COUNTRY_NAMES_TABLE,
  filterKey: HILOS_I18N_COUNTRY_NAMES_FILTER,
  frame: {
    columns: HILOS_I18N_NAMES_COLUMNS,
    empty: { title: 'No languages yet' },
  },
}

/** A names table the view drives: the controller plus its mount lifecycle. */
export interface HilosI18nNamesTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<HilosI18nNameRow>
  /** Bind the table and follow the address's subject — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The names table of one subject. The subject's code is a preset of the filter
 * map, so a cold window built without it is not drawn and the table asks for its
 * own; moving to another subject on the same page sets the filter again, which
 * takes a new window and closes every open row.
 *
 * @param context The project's connection and page scopes.
 * @param target Which of the two names tables this is.
 * @param subject The code of the language or country the address names.
 */
export function createHilosI18nNamesTable(
  context: HilosI18nLanguageContext,
  target: HilosI18nNamesTarget,
  subject: ReadonlySignal<string>,
): HilosI18nNamesTable {
  const controller = new TableViewportController<HilosI18nNameRow>({
    resolve: resolveHilosI18nNameRow,
    expandable: hilosI18nNameRowExpandable,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        target.page,
        target.tableKey,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        target.page,
        target.tableKey,
        rendered,
      ),
    initialFilter: { [target.filterKey]: subject.get() },
    frame: target.frame,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: target.page, tableKey: target.tableKey },
          controller,
        ),
        subscribeSignal(subject, (value) =>
          controller.setFilter(target.filterKey, value),
        ),
      ]
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}
