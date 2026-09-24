// The framework Hilos two-step verification admin headless (HIL-494): the
// settings view-model, its row resolver, and the table / actions factories the
// per-framework HilosSecurity2faPage views render from. Framework-agnostic
// (imports no UI framework), so the three views stay thin
// (multiframework-core.md).
//
// The rows come from the settings in force: one per second-factor setting, its
// value and the catalog default as the text the screen shows and edits. A row
// changes when the setting does, whichever door wrote it, so the screen needs no
// signal of its own. Editing is a modal per row (the modal-only editing rule),
// and a value a setting's rule refuses is answered on the action's `::fail` —
// the modal keeps it and says why.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/**
 * The six second-factor settings, by the key the setting is stored under (PHP
 * `SecondFactorSettings::*_KEY`). The screen names each row by its key, so the
 * keys are the core's to spell once.
 */
export const HilosSecondFactorSettingKey = {
  required: 'auth.second_factor.required',
  trustDays: 'auth.second_factor.trust_days',
  backupCodes: 'auth.second_factor.backup_codes',
  resetWaitDefaultDays: 'auth.second_factor.reset_wait_default_days',
  resetWaitMinDays: 'auth.second_factor.reset_wait_min_days',
  resetWaitMaxDays: 'auth.second_factor.reset_wait_max_days',
} as const

/** Who must use a second factor — the values of the `required` setting. */
export const HILOS_SECOND_FACTOR_REQUIRED_VALUES = [
  'none',
  'admins',
  'everyone',
] as const

/** What the screen says about one setting: its name and the line under the value. */
export interface HilosSecondFactorSettingCopy {
  /** The setting's name on the screen. */
  readonly label: string
  /** What the value means and where it may go. */
  readonly hint: string
}

/**
 * The words of the six settings, by key. Framework copy rather than each view's
 * own: three view layers draw the same six rows and the same six modals, and
 * eighteen copies of one sentence drift (the reason `oauthTripMessage` is here).
 */
export const HILOS_SECOND_FACTOR_SETTING_COPY: Readonly<
  Record<string, HilosSecondFactorSettingCopy>
> = {
  [HilosSecondFactorSettingKey.required]: {
    label: 'Required for',
    hint: 'Who must use two-step verification. They set it up when they next sign in.',
  },
  [HilosSecondFactorSettingKey.trustDays]: {
    label: 'Trusted device',
    hint: 'Days a browser skips the code after "Don\'t ask again". 0 to 365; 0 turns the option off.',
  },
  [HilosSecondFactorSettingKey.backupCodes]: {
    label: 'Backup codes',
    hint: 'Codes in one set, 1 to 20. A set already issued keeps its size.',
  },
  [HilosSecondFactorSettingKey.resetWaitDefaultDays]: {
    label: 'Removal wait',
    hint: 'Days a removal waits for a person who chose no wait of their own.',
  },
  [HilosSecondFactorSettingKey.resetWaitMinDays]: {
    label: 'Shortest removal wait',
    hint: 'The least a person may choose, at least 1 day.',
  },
  [HilosSecondFactorSettingKey.resetWaitMaxDays]: {
    label: 'Longest removal wait',
    hint: 'The most a person may choose, up to 365 days.',
  },
}

/** Who must use a second factor, as the screen names each value of `required`. */
export const HILOS_SECOND_FACTOR_REQUIRED_COPY: Readonly<
  Record<(typeof HILOS_SECOND_FACTOR_REQUIRED_VALUES)[number], string>
> = {
  none: 'Nobody',
  admins: 'Administrators',
  everyone: 'Everyone',
}

/**
 * A setting's value as the screen says it: who is required by name, a trust of
 * zero days as off, and a count of days or codes with its unit.
 *
 * @param settingKey The setting the value belongs to.
 * @param value The value as text, as the row carries it.
 * @returns The value in words.
 */
export function describeHilosSecondFactorSetting(
  settingKey: string,
  value: string,
): string {
  if (settingKey === HilosSecondFactorSettingKey.required) {
    return (
      HILOS_SECOND_FACTOR_REQUIRED_COPY[
        value as keyof typeof HILOS_SECOND_FACTOR_REQUIRED_COPY
      ] ?? value
    )
  }
  if (settingKey === HilosSecondFactorSettingKey.trustDays && value === '0') {
    return 'Off'
  }
  if (settingKey === HilosSecondFactorSettingKey.backupCodes) {
    return `${value} codes`
  }

  return `${value} days`
}

/** One row of the two-step verification table — the framework settings view-model. */
export interface HilosTwoFactorSettingRow {
  /** The setting key the row stands for; also the table row key. */
  readonly rowKey: string
  /** The value in force, as text. */
  readonly value: string
  /** The catalog default, as text. */
  readonly defaultValue: string
}

// Wire keys: the framework two-step verification table, its inline row slot, and
// the edit action. A project binds its backend to these (Hilos::TABLES / PAGE_TABLES).
const TWO_FACTOR_TABLE = 'hilosSecurityTwoFactor'
const TWO_FACTOR_SLOT = 'setting'
const SETTING_SET_ACTION = 'security_2fa_setting_set'

/**
 * Payload keys of the two-step verification row slot (mirrors the backend row
 * shape, PHP `HilosSecurityTwoFactorTableRow`). Exported because the view
 * declares its columns from the same keys.
 */
export const HilosTwoFactorSettingRowKey = {
  rowKey: 'rowKey',
  value: 'value',
  defaultValue: 'defaultValue',
} as const

/**
 * The project-supplied context the two-step verification admin reads from: the
 * scope-partitioned stores that own the page-scoped table, the connection the
 * table window rides, and the action lifecycle the edit dispatches over.
 */
export interface HilosTwoFactorContext {
  /** The connection the table sends its viewport over and receives windows / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope of the table. */
  readonly scopes: ScopeManager
  /** The action lifecycle the tracked edit dispatches over. */
  readonly actions: ActionLifecycle
}

/** The edit a two-step verification view binds to. */
export interface HilosTwoFactorActions {
  /**
   * Store one setting, as a tracked action. The backend refuses a key that is
   * not one of the six and a value the setting's rule refuses, on the action's
   * `::fail`; the row redraws when the setting is written.
   *
   * @param settingKey The setting to store.
   * @param value The value as the person typed or picked it.
   */
  sendSettingSet(settingKey: string, value: string): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw two-step verification row into its view-model. The setting
 * rides a single inline `setting` slot, keyed by the setting key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosTwoFactorSettingRow(
  row: TableRow,
): HilosTwoFactorSettingRow {
  const slot = recordSlot(row.slots[TWO_FACTOR_SLOT]) ?? {}

  return {
    rowKey:
      readString(slot, HilosTwoFactorSettingRowKey.rowKey) ||
      String(row.rowKey),
    value: readString(slot, HilosTwoFactorSettingRowKey.value),
    defaultValue: readString(slot, HilosTwoFactorSettingRowKey.defaultValue),
  }
}

/** The two-step verification table handle a view drives: the controller and the lifecycle. */
export interface HilosTwoFactorTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosTwoFactorSettingRow>
  /** Bind the table to the connection — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/** The columns of the two-step verification table, in display order. */
const TWO_FACTOR_COLUMNS: HilosTableColumnOf<HilosTwoFactorSettingRow>[] = [
  {
    key: HilosTwoFactorSettingRowKey.rowKey,
    label: 'Setting',
  },
  {
    key: HilosTwoFactorSettingRowKey.value,
    label: 'Value',
    // The value is told apart from the default beside it.
    reads: [HilosTwoFactorSettingRowKey.defaultValue],
  },
  {
    // The pencil that opens the row's modal (the modal-only editing rule); the
    // modal shows the value and the default beside it.
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    reads: [
      HilosTwoFactorSettingRowKey.value,
      HilosTwoFactorSettingRowKey.defaultValue,
    ],
  },
]

/**
 * What the two-step verification table declares about its frame. No search:
 * there are six rows. No title: the page heading already names it.
 */
const TWO_FACTOR_FRAME: HilosTableFrame = {
  columns: TWO_FACTOR_COLUMNS,
  empty: { title: 'No two-step verification settings.' },
}

/**
 * The server-windowed controller for the two-step verification table. Rows
 * resolve through {@link resolveHilosTwoFactorSettingRow}.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosSecurityTwoFactorTable(
  context: HilosTwoFactorContext,
): HilosTwoFactorTable {
  const controller = new TableViewportController<HilosTwoFactorSettingRow>({
    resolve: resolveHilosTwoFactorSettingRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.SECURITY_2FA,
        TWO_FACTOR_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.SECURITY_2FA,
        TWO_FACTOR_TABLE,
        rendered,
      ),
    frame: TWO_FACTOR_FRAME,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.SECURITY_2FA, tableKey: TWO_FACTOR_TABLE },
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

/**
 * The two-step verification edit: a tracked action over the lifecycle. The
 * handle's `done` resolves on the backend's `::success` and rejects on `::fail`
 * — the modal keeps the value and shows the refusal.
 *
 * @param context The project context (the action lifecycle the edit dispatches over).
 */
export function createHilosSecurityTwoFactorActions(
  context: Pick<HilosTwoFactorContext, 'actions'>,
): HilosTwoFactorActions {
  return {
    sendSettingSet(settingKey, value) {
      return context.actions.dispatch(SETTING_SET_ACTION, {
        key: settingKey,
        value,
      })
    },
  }
}
