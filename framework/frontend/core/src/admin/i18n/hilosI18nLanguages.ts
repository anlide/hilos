// The languages table (HIL-1474): every language of the system on one page.
// The code is the row key and the link into the language card. Default and
// custom are marks the server computes; this module only resolves them.
// Framework-agnostic: the views render from what this module resolves.
import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'

/** Table key of the languages list, as the backend registers it. */
export const HILOS_I18N_LANGUAGES_TABLE = 'hilosI18nLanguages'

/** The row slot a language row rides under (mirrors the backend ROW_SLOT). */
const LANGUAGE_SLOT = 'language'

/** Payload keys of a language row (mirrors HilosI18nLanguagesTableRow). */
export const HilosI18nLanguageRowKey = {
  code: 'code',
  nativeName: 'nativeName',
  rtl: 'rtl',
  enabled: 'enabled',
  isDefault: 'isDefault',
  isOwn: 'isOwn',
} as const

const languageSlotSchema = z.object({
  [HilosI18nLanguageRowKey.code]: z.string(),
  [HilosI18nLanguageRowKey.nativeName]: z.string(),
  [HilosI18nLanguageRowKey.rtl]: z.boolean(),
  [HilosI18nLanguageRowKey.enabled]: z.boolean(),
  [HilosI18nLanguageRowKey.isDefault]: z.boolean(),
  [HilosI18nLanguageRowKey.isOwn]: z.boolean(),
})

/** One row of the languages table. */
export interface HilosI18nLanguageRow {
  /** Language code; also the row key and the card address. */
  readonly code: string
  /** The name the language calls itself. */
  readonly nativeName: string
  /** Whether writing runs right to left. */
  readonly rtl: boolean
  /** Whether the language is switched on. */
  readonly enabled: boolean
  /** Whether this is the installation default. */
  readonly isDefault: boolean
  /** Whether the code is absent from the built-in catalog. */
  readonly isOwn: boolean
}

/**
 * Resolve one raw language row into its view-model. A slot the schema refuses
 * shows the language by its key, switched off, rather than dropping the row.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosI18nLanguageRow(
  row: TableRow,
): HilosI18nLanguageRow {
  const parsed = languageSlotSchema.safeParse(row.slots[LANGUAGE_SLOT])
  if (parsed.success) {
    return parsed.data
  }
  const code = String(row.rowKey)

  return {
    code,
    nativeName: code,
    rtl: false,
    enabled: false,
    isDefault: false,
    isOwn: false,
  }
}

/** The columns of the languages table, in display order. */
export const HILOS_I18N_LANGUAGES_COLUMNS: readonly HilosTableColumnOf<HilosI18nLanguageRow>[] =
  [
    {
      key: HilosI18nLanguageRowKey.code,
      label: 'Code',
      sortable: true,
      card: 'title',
      reads: [HilosI18nLanguageRowKey.enabled],
    },
    {
      key: HilosI18nLanguageRowKey.nativeName,
      label: 'Native name',
      sortable: true,
      reads: [
        HilosI18nLanguageRowKey.isDefault,
        HilosI18nLanguageRowKey.isOwn,
        HilosI18nLanguageRowKey.enabled,
      ],
    },
    {
      key: HilosI18nLanguageRowKey.rtl,
      label: 'RTL',
      sortable: true,
      reads: [HilosI18nLanguageRowKey.enabled],
    },
    {
      key: HilosI18nLanguageRowKey.enabled,
      label: 'Enabled',
      sortable: true,
    },
  ]

/**
 * The languages frame. The page heading names the one table, so the frame
 * has no title of its own, and a language from the environment is always
 * present, so there is no empty state.
 */
export const HILOS_I18N_LANGUAGES_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search languages…' },
  columns: HILOS_I18N_LANGUAGES_COLUMNS,
}

/** The languages table the view drives: the controller plus its mount lifecycle. */
export interface HilosI18nLanguagesTable {
  /** The server-windowed controller the view renders rows from. */
  readonly controller: TableViewportController<HilosI18nLanguageRow>
  /** Bind the table — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The languages table. Rows resolve through {@link resolveHilosI18nLanguageRow}.
 *
 * @param context The project's connection and page scopes.
 */
export function createHilosI18nLanguagesTable(
  context: HilosI18nLanguageContext,
): HilosI18nLanguagesTable {
  const controller = new TableViewportController<HilosI18nLanguageRow>({
    resolve: resolveHilosI18nLanguageRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.I18N_LANGUAGES,
        HILOS_I18N_LANGUAGES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.I18N_LANGUAGES,
        HILOS_I18N_LANGUAGES_TABLE,
        rendered,
      ),
    frame: HILOS_I18N_LANGUAGES_FRAME,
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
            page: HilosPages.I18N_LANGUAGES,
            tableKey: HILOS_I18N_LANGUAGES_TABLE,
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
