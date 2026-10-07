// The read-only Appearance table over the installation's two theme settings.
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readHideableBoolean,
  readHideableString,
} from '../../state/fieldReaders.js'
import { type Hideable } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** Framework-owned browser table containing the two theme settings. */
export const HILOS_APPEARANCE_SETTINGS_TABLE = 'hilosAppearanceSettings'
const SETTING_SLOT = 'setting'

/** Row payload key of the stable setting key. */
export const APPEARANCE_ROW_KEY_FIELD = 'rowKey'
/** Row payload key of the current value. */
export const APPEARANCE_VALUE_FIELD = 'value'
/** Row payload key of the catalog default. */
export const APPEARANCE_DEFAULT_VALUE_FIELD = 'defaultValue'

/** The two setting identities shared with the framework catalog. */
export const HilosAppearanceSettingKey = {
  switchingEnabled: 'theme.switching_enabled',
  defaultTheme: 'theme.default',
} as const

/** The page reads from the project connection and its page scopes. */
export interface HilosAppearanceContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** The two values keep their actual wire types, even when hidden. */
export interface HilosAppearanceSettingRow {
  readonly rowKey: string
  readonly value: Hideable<boolean | string>
  readonly defaultValue: Hideable<boolean | string>
}

/** Resolves the inline setting slot without converting a boolean to text. */
export function resolveHilosAppearanceSettingRow(
  row: TableRow,
): HilosAppearanceSettingRow {
  const raw = row.slots[SETTING_SLOT]
  const slot =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}
  const rowKey = String(row.rowKey)

  if (rowKey === HilosAppearanceSettingKey.switchingEnabled) {
    return {
      rowKey,
      value: readHideableBoolean(slot, APPEARANCE_VALUE_FIELD),
      defaultValue: readHideableBoolean(slot, APPEARANCE_DEFAULT_VALUE_FIELD),
    }
  }

  return {
    rowKey,
    value: readHideableString(slot, APPEARANCE_VALUE_FIELD),
    defaultValue: readHideableString(slot, APPEARANCE_DEFAULT_VALUE_FIELD),
  }
}

const COLUMNS: HilosTableColumnOf<HilosAppearanceSettingRow>[] = [
  { key: APPEARANCE_ROW_KEY_FIELD, label: 'Setting' },
  { key: APPEARANCE_VALUE_FIELD, label: 'Current value' },
  { key: APPEARANCE_DEFAULT_VALUE_FIELD, label: 'Default' },
]

const FRAME: HilosTableFrame = {
  columns: COLUMNS,
  empty: { title: 'No appearance settings available.' },
}

/** A mounted table subscribes to the page's first window and its live deltas. */
export interface HilosAppearanceTable {
  readonly controller: TableViewportController<HilosAppearanceSettingRow>
  start(): void
  dispose(): void
}

/**
 * @param context Project connection and scope stores
 * @return HilosAppearanceTable Read-only table handle
 */
export function createHilosAppearanceTable(
  context: HilosAppearanceContext,
): HilosAppearanceTable {
  const controller = new TableViewportController<HilosAppearanceSettingRow>({
    resolve: resolveHilosAppearanceSettingRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.APPEARANCE,
        HILOS_APPEARANCE_SETTINGS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.APPEARANCE,
        HILOS_APPEARANCE_SETTINGS_TABLE,
        rendered,
      ),
    frame: FRAME,
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.APPEARANCE,
            tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
    },
  }
}
