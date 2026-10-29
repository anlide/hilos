// The languages table (HIL-1474): every language of the system on one page.
// The decimal language ID is the row key; the code links to the language card. Default and
// custom are marks the server computes; this module only resolves them.
// Framework-agnostic: the views render from what this module resolves.
import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'
import {
  type HilosI18nLegendRow,
  type HilosI18nMark,
} from './hilosI18nMarks.js'

/** Table key of the languages list, as the backend registers it. */
export const HILOS_I18N_LANGUAGES_TABLE = 'hilosI18nLanguages'

/** The row slot a language row rides under (mirrors the backend ROW_SLOT). */
const LANGUAGE_SLOT = 'language'

/** Payload keys of a language row (mirrors HilosI18nLanguagesTableRow). */
export const HilosI18nLanguageRowKey = {
  rowKey: 'rowKey',
  languageId: 'languageId',
  code: 'code',
  nativeName: 'nativeName',
  rtl: 'rtl',
  enabled: 'enabled',
  isDefault: 'isDefault',
  isOwn: 'isOwn',
  canDelete: 'canDelete',
  deleteReason: 'deleteReason',
  deleteDisabledReason: 'deleteDisabledReason',
} as const

const languageSlotSchema = z.object({
  [HilosI18nLanguageRowKey.rowKey]: z.string(),
  [HilosI18nLanguageRowKey.languageId]: z.number().int().positive(),
  [HilosI18nLanguageRowKey.code]: z.string(),
  [HilosI18nLanguageRowKey.nativeName]: z.string(),
  [HilosI18nLanguageRowKey.rtl]: z.boolean(),
  [HilosI18nLanguageRowKey.enabled]: z.boolean(),
  [HilosI18nLanguageRowKey.isDefault]: z.boolean(),
  [HilosI18nLanguageRowKey.isOwn]: z.boolean(),
  [HilosI18nLanguageRowKey.canDelete]: z.boolean(),
  [HilosI18nLanguageRowKey.deleteReason]: z
    .enum(['default', 'locales', 'names'])
    .nullable(),
  [HilosI18nLanguageRowKey.deleteDisabledReason]: z.string().nullable(),
})

/** One row of the languages table. */
export interface HilosI18nLanguageRow {
  readonly rowKey: string
  readonly languageId: number
  /** Display code and external card address. */
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
  /** Whether the current server verdict permits deletion. */
  readonly canDelete: boolean
  /** The first blocking dependency, or null when deletion is allowed. */
  readonly deleteReason: 'default' | 'locales' | 'names' | null
  /** The server's sentence for {@link deleteReason}, or null when deletion is allowed. */
  readonly deleteDisabledReason: string | null
}

/**
 * Resolve the typed language projection, refusing absent or malformed IDs.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosI18nLanguageRow(
  row: TableRow,
): HilosI18nLanguageRow {
  return languageSlotSchema.parse(row.slots[LANGUAGE_SLOT])
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
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [
        HilosI18nLanguageRowKey.enabled,
        HilosI18nLanguageRowKey.nativeName,
        HilosI18nLanguageRowKey.rtl,
        HilosI18nLanguageRowKey.isOwn,
        HilosI18nLanguageRowKey.canDelete,
        HilosI18nLanguageRowKey.deleteReason,
        HilosI18nLanguageRowKey.deleteDisabledReason,
      ],
      headerClass: 'text-end',
      cellClass: 'text-end text-nowrap',
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

/** The page's modal opener bound into this table's frame. */
export interface HilosI18nLanguagesTableView {
  readonly openAdd: () => void
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
 * @param view The page's language creation opener.
 */
export function createHilosI18nLanguagesTable(
  context: HilosI18nLanguageContext,
  view: HilosI18nLanguagesTableView,
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
    sendFocus: (rowKey) =>
      context.connection.sendTableRowFocus(
        HilosPages.I18N_LANGUAGES,
        HILOS_I18N_LANGUAGES_TABLE,
        rowKey,
      ),
    frame: {
      ...HILOS_I18N_LANGUAGES_FRAME,
      mainAction: { label: 'Add language', press: view.openAdd },
    },
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

/** Pre-defined marks for language rows in the table and legend. */
export const HILOS_I18N_LANGUAGE_MARKS: Readonly<{
  readonly default: HilosI18nMark
  readonly own: HilosI18nMark
  readonly frozen: HilosI18nMark
  readonly editable: HilosI18nMark
  readonly deleteUnavailable: HilosI18nMark
}> = {
  default: {
    icon: 'bi-star-fill',
    tone: 'text-bg-primary-subtle text-primary-emphasis',
    label: 'Default language',
  },
  own: {
    icon: 'bi-question-circle',
    tone: 'text-bg-warning-subtle text-warning-emphasis',
    label: 'Custom language',
  },
  frozen: {
    icon: 'bi-eye',
    tone: 'border border-secondary text-body-secondary',
    label: 'Frozen language',
  },
  editable: {
    icon: 'bi-pencil',
    tone: 'border border-primary text-primary',
    label: 'Editable language',
  },
  deleteUnavailable: {
    icon: 'bi-trash',
    tone: 'border border-secondary text-body-secondary',
    label: 'Deletion unavailable',
  },
}

/** Legend items explaining marks on the languages page. */
export const HILOS_I18N_LANGUAGES_LEGEND: ReadonlyArray<HilosI18nLegendRow> = [
  {
    key: 'default',
    mark: HILOS_I18N_LANGUAGE_MARKS.default,
    meaning:
      'the language from the environment; it can be neither switched off nor deleted',
  },
  {
    key: 'own',
    mark: HILOS_I18N_LANGUAGE_MARKS.own,
    meaning:
      'its code is not in the built-in catalog: nothing refreshes it, keeping it up is yours',
  },
  {
    key: 'frozen',
    mark: HILOS_I18N_LANGUAGE_MARKS.frozen,
    meaning: 'the language is enabled and frozen: nobody changes its live row',
  },
  {
    key: 'editable',
    mark: HILOS_I18N_LANGUAGE_MARKS.editable,
    meaning: 'the language is switched off: edit it freely',
  },
  {
    key: 'deleteUnavailable',
    mark: HILOS_I18N_LANGUAGE_MARKS.deleteUnavailable,
    meaning: 'deletion is unavailable: the language can still be switched off',
  },
]
