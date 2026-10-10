// The countries table (HIL-1475): every country of the system on one page.
// The code is the row key and the link into the country card. The name and the
// default locale arrive empty when none is written; the view draws the code
// and the "Not chosen" badge. Framework-agnostic: the views render from what
// this module resolves.
import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosI18nCountryContext } from './hilosI18nCountry.js'

/** Table key of the countries list, as the backend registers it. */
export const HILOS_I18N_COUNTRIES_TABLE = 'hilosI18nCountries'

/** The row slot a country row rides under (mirrors the backend ROW_SLOT). */
const COUNTRY_SLOT = 'country'

/** Payload keys of a country row (mirrors HilosI18nCountriesTableRow). */
export const HilosI18nCountryRowKey = {
  code: 'code',
  name: 'name',
  currencySymbol: 'currencySymbol',
  currencyCode: 'currencyCode',
  defaultLocaleCode: 'defaultLocaleCode',
  enabled: 'enabled',
  isOwn: 'isOwn',
} as const

const countrySlotSchema = z.object({
  [HilosI18nCountryRowKey.code]: z.string(),
  [HilosI18nCountryRowKey.name]: z.string().nullable(),
  [HilosI18nCountryRowKey.currencySymbol]: z.string(),
  [HilosI18nCountryRowKey.currencyCode]: z.string(),
  [HilosI18nCountryRowKey.defaultLocaleCode]: z.string().nullable(),
  [HilosI18nCountryRowKey.enabled]: z.boolean(),
  [HilosI18nCountryRowKey.isOwn]: z.boolean(),
})

/** One row of the countries table. */
export interface HilosI18nCountryRow {
  /** Country code; also the row key and the card address. */
  readonly code: string
  /** Base name in the default language, or null when none is written. */
  readonly name: string | null
  /** Symbol displayed with an amount. */
  readonly currencySymbol: string
  /** Three-letter currency code. */
  readonly currencyCode: string
  /** Code of the default locale, or null when none is chosen. */
  readonly defaultLocaleCode: string | null
  /** Whether the country is switched on. */
  readonly enabled: boolean
  /** Whether the code is absent from the built-in catalog. */
  readonly isOwn: boolean
}

/**
 * Resolve one raw country row into its view-model. A slot the schema refuses
 * shows the country by its key, switched off, rather than dropping the row.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosI18nCountryRow(row: TableRow): HilosI18nCountryRow {
  const parsed = countrySlotSchema.safeParse(row.slots[COUNTRY_SLOT])
  if (parsed.success) {
    return parsed.data
  }
  const code = String(row.rowKey)

  return {
    code,
    name: null,
    currencySymbol: '',
    currencyCode: '',
    defaultLocaleCode: null,
    enabled: false,
    isOwn: false,
  }
}

/** The columns of the countries table, in display order. */
export const HILOS_I18N_COUNTRIES_COLUMNS: readonly HilosTableColumnOf<HilosI18nCountryRow>[] =
  [
    {
      key: HilosI18nCountryRowKey.code,
      label: 'Code',
      sortable: true,
      card: 'title',
      reads: [HilosI18nCountryRowKey.enabled],
    },
    {
      key: HilosI18nCountryRowKey.name,
      label: 'Name',
      sortable: true,
      reads: [
        HilosI18nCountryRowKey.code,
        HilosI18nCountryRowKey.isOwn,
        HilosI18nCountryRowKey.enabled,
      ],
    },
    {
      key: HilosI18nCountryRowKey.currencyCode,
      label: 'Currency',
      sortable: true,
      reads: [
        HilosI18nCountryRowKey.currencySymbol,
        HilosI18nCountryRowKey.enabled,
      ],
    },
    {
      key: HilosI18nCountryRowKey.defaultLocaleCode,
      label: 'Default locale',
      sortable: true,
      reads: [HilosI18nCountryRowKey.enabled],
    },
    {
      key: HilosI18nCountryRowKey.enabled,
      label: 'Enabled',
      sortable: true,
    },
  ]

/**
 * The countries frame. The page heading names the one table, so the frame
 * has no title of its own, and known countries are not removed, so there is
 * no empty state.
 */
export const HILOS_I18N_COUNTRIES_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search countries…' },
  columns: HILOS_I18N_COUNTRIES_COLUMNS,
}

/** The countries table the view drives: the controller plus its mount lifecycle. */
export interface HilosI18nCountriesTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<HilosI18nCountryRow>
  /** Bind the table — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The countries table. Rows resolve through {@link resolveHilosI18nCountryRow}.
 *
 * @param context The project's connection and page scopes.
 */
export function createHilosI18nCountriesTable(
  context: HilosI18nCountryContext,
): HilosI18nCountriesTable {
  const controller = new TableViewportController<HilosI18nCountryRow>({
    resolve: resolveHilosI18nCountryRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.I18N_COUNTRIES,
        HILOS_I18N_COUNTRIES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.I18N_COUNTRIES,
        HILOS_I18N_COUNTRIES_TABLE,
        rendered,
      ),
    frame: HILOS_I18N_COUNTRIES_FRAME,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.I18N_COUNTRIES,
            tableKey: HILOS_I18N_COUNTRIES_TABLE,
          },
          controller,
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
