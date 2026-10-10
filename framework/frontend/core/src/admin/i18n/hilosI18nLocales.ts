// The locales table of one language (HIL-1476). A row is a pair of the language the
// page's address names and one country of the system, or the language alone — not a
// stored locale: every pair is a row whether a locale of it exists or not. It carries
// the country's code and its base name in that language, and, when there is a locale,
// its code and whether it is on. The server windows the table per language, so the
// language travels as a preset of the filter map and is set again when the address
// changes; nothing is filtered or sorted on the client.
//
// Framework-agnostic: the views render from what this module resolves.
import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { subscribeSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'

/** Table key of the locales of one language, as the backend registers it. */
export const HILOS_I18N_LANGUAGE_LOCALES_TABLE = 'hilosI18nLanguageLocales'

/** Filter key carrying the code of the language the locales table is opened on. */
export const HILOS_I18N_LANGUAGE_LOCALES_FILTER = 'language'

/** The row slot a locales row rides under (mirrors the backend ROW_SLOT). */
const LOCALES_SLOT = 'locale'

/** Payload keys of a locales row (mirrors the backend HilosI18nLanguageLocalesTableRow). */
export const HilosI18nLocaleRowKey = {
  rowKey: 'rowKey',
  countryCode: 'countryCode',
  countryName: 'countryName',
  localeCode: 'localeCode',
  enabled: 'enabled',
  formats: 'formats',
  catalogFormats: 'catalogFormats',
} as const

/** Seven display formats of one locale or one built-in catalog entry. */
export const hilosI18nLocaleFormatsSchema = z.strictObject({
  date: z.string(),
  time: z.string(),
  number: z.string(),
  phone: z.string(),
  address: z.string(),
  measurement: z.enum(['metric', 'imperial']),
  collation: z.string(),
})

export type HilosI18nLocaleFormats = z.infer<
  typeof hilosI18nLocaleFormatsSchema
>

const localeSlotSchema = z.object({
  [HilosI18nLocaleRowKey.rowKey]: z.string(),
  [HilosI18nLocaleRowKey.countryCode]: z.string().nullable(),
  [HilosI18nLocaleRowKey.countryName]: z.string().nullable(),
  [HilosI18nLocaleRowKey.localeCode]: z.string().nullable(),
  [HilosI18nLocaleRowKey.enabled]: z.boolean().nullable(),
  [HilosI18nLocaleRowKey.formats]: hilosI18nLocaleFormatsSchema.nullable(),
  [HilosI18nLocaleRowKey.catalogFormats]:
    hilosI18nLocaleFormatsSchema.nullable(),
})

/** One row of the locales table — a pair of the language and a country, or the language alone. */
export interface HilosI18nLocaleRow {
  /** The pair, written as the code of its locale (`ru`, `ru-UA`); also the row key. */
  readonly rowKey: string
  /** Code of the row's country, as stored (the view writes it in capitals); null on the language's own row. */
  readonly countryCode: string | null
  /** The country's base name in the card's language, or null when none is written. */
  readonly countryName: string | null
  /** Code of the pair's locale, or null when the pair has no locale. */
  readonly localeCode: string | null
  /** Whether the pair's locale is on, or null when the pair has no locale. */
  readonly enabled: boolean | null
  /** Stored formats, or null when the pair has no locale. */
  readonly formats: HilosI18nLocaleFormats | null
  /** Built-in formats, or null when the catalog does not know the pair. */
  readonly catalogFormats: HilosI18nLocaleFormats | null
}

/**
 * Resolve one raw locales row into its view-model. A slot the schema refuses —
 * which only a server of another version can send — shows the pair by its key with
 * no locale, rather than dropping the row.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosI18nLocaleRow(row: TableRow): HilosI18nLocaleRow {
  const parsed = localeSlotSchema.safeParse(row.slots[LOCALES_SLOT])
  if (parsed.success) {
    return parsed.data
  }

  return {
    rowKey: String(row.rowKey),
    countryCode: null,
    countryName: null,
    localeCode: null,
    enabled: null,
    formats: null,
    catalogFormats: null,
  }
}

/** The columns of the locales table, in display order. */
export const HILOS_I18N_LANGUAGE_LOCALES_COLUMNS: readonly HilosTableColumnOf<HilosI18nLocaleRow>[] =
  [
    {
      key: HilosI18nLocaleRowKey.countryCode,
      label: 'Country',
      card: 'title',
      // The label, and the muting of a switched-off locale's text.
      reads: [
        HilosI18nLocaleRowKey.countryName,
        HilosI18nLocaleRowKey.localeCode,
        HilosI18nLocaleRowKey.enabled,
      ],
    },
    {
      key: HilosI18nLocaleRowKey.localeCode,
      label: 'Code',
      reads: [HilosI18nLocaleRowKey.enabled],
    },
    { key: HilosI18nLocaleRowKey.enabled, label: 'Enabled' },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [
        HilosI18nLocaleRowKey.localeCode,
        HilosI18nLocaleRowKey.enabled,
        HilosI18nLocaleRowKey.formats,
        HilosI18nLocaleRowKey.catalogFormats,
      ],
      headerClass: 'text-end',
      cellClass: 'text-end text-nowrap',
    },
  ]

/**
 * The frame of the locales table: its columns and nothing else. The page's heading
 * names the table, there is no search, and no empty state is declared — a language
 * that exists always has the row of the language alone.
 */
export const HILOS_I18N_LANGUAGE_LOCALES_FRAME: HilosTableFrame = {
  columns: HILOS_I18N_LANGUAGE_LOCALES_COLUMNS,
}

/** The locales table the view drives: the controller plus its mount lifecycle. */
export interface HilosI18nLanguageLocalesTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<HilosI18nLocaleRow>
  /** Bind the table and follow the address's language — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The locales table of one language. The language's code is a preset of the
 * filter map, so a cold window built without it is not drawn and the table asks for
 * its own; moving to another language on the same page sets the filter again, which
 * takes a new window.
 *
 * @param context The project's connection and page scopes.
 * @param language The code of the language the address names.
 */
export function createHilosI18nLanguageLocalesTable(
  context: HilosI18nLanguageContext,
  language: ReadonlySignal<string>,
): HilosI18nLanguageLocalesTable {
  const page = HilosPages.I18N_LANGUAGE_LOCALES
  const tableKey = HILOS_I18N_LANGUAGE_LOCALES_TABLE
  const controller = new TableViewportController<HilosI18nLocaleRow>({
    resolve: resolveHilosI18nLocaleRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(page, tableKey, descriptor),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(page, tableKey, rendered),
    sendFocus: (rowKey) =>
      context.connection.sendTableRowFocus(page, tableKey, rowKey),
    initialFilter: { [HILOS_I18N_LANGUAGE_LOCALES_FILTER]: language.get() },
    frame: HILOS_I18N_LANGUAGE_LOCALES_FRAME,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          { page, tableKey },
          controller,
        ),
        subscribeSignal(language, (value) =>
          controller.setFilter(HILOS_I18N_LANGUAGE_LOCALES_FILTER, value),
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
