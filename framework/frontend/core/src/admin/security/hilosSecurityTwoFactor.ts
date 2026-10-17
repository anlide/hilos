// The framework Hilos two-step verification admin headless (HIL-494): the
// settings view-model, its row resolver, and the table / actions factories the
// per-framework HilosSecurity2faPage views render from. Framework-agnostic
// (imports no UI framework), so the three views stay thin
// (multiframework-core.md).
//
// The rows come from the settings in force: one per second-factor setting, its
// value and the catalog default as the text the screen shows and edits —
// `true` / `false` for the two switches. A row changes when the setting does,
// whichever door wrote it, so the screen needs no signal of its own. The
// switches write at once (a mutation without a parameter), but turning the
// factor off goes through its own window (hilosSecurityTwoFactorDisable.ts);
// off, every row below the first is locked (HIL-1303). Editing a value is a
// modal per row (the modal-only editing rule), and a value a setting's rule
// refuses is answered on the action's `::fail` — the modal keeps it and says
// why.

import {
  HIDDEN_VALUE,
  isHiddenValue,
  type Hideable,
} from '../../state/hiddenValue.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readHideableString, readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/**
 * The eight second-factor settings, by the key the setting is stored under (PHP
 * `SecondFactorSettings::*_KEY`), in the order the screen lists them. The
 * screen names each row by its key, so the keys are the core's to spell once.
 */
export const HilosSecondFactorSettingKey = {
  enabled: 'auth.second_factor.enabled',
  forgetTrustsOnDisable: 'auth.second_factor.forget_trusts_on_disable',
  required: 'auth.second_factor.required',
  trustDays: 'auth.second_factor.trust_days',
  backupCodes: 'auth.second_factor.backup_codes',
  resetWaitDefaultDays: 'auth.second_factor.reset_wait_default_days',
  resetWaitMinDays: 'auth.second_factor.reset_wait_min_days',
  resetWaitMaxDays: 'auth.second_factor.reset_wait_max_days',
} as const

/** The text a switch row carries when it is on. */
const SWITCH_ON = 'true'

/** The text a switch row carries when it is off. */
const SWITCH_OFF = 'false'

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
 * The words of the eight settings, by key. Framework copy rather than each
 * view's own: three view layers draw the same eight rows and the same six
 * modals, and three copies of one sentence drift (the reason `oauthTripMessage`
 * is here).
 */
export const HILOS_SECOND_FACTOR_SETTING_COPY: Readonly<
  Record<string, HilosSecondFactorSettingCopy>
> = {
  [HilosSecondFactorSettingKey.enabled]: {
    label: 'Two-factor enabled',
    hint: "Turn it off and no sign-in asks for a code; people's apps and backup codes are kept.",
  },
  [HilosSecondFactorSettingKey.forgetTrustsOnDisable]: {
    label: 'Forget trusted browsers when turned off',
    hint: 'Trusted browsers ask for the code again once two-factor is back on.',
  },
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
 * Whether a setting is one of the two switches, drawn as a switch and written
 * by `switchSet`; the other six are values edited in a modal.
 *
 * @param settingKey The setting key.
 * @returns True for a yes-or-no setting.
 */
export function isHilosSecondFactorSwitch(settingKey: string): boolean {
  return (
    settingKey === HilosSecondFactorSettingKey.enabled ||
    settingKey === HilosSecondFactorSettingKey.forgetTrustsOnDisable
  )
}

/**
 * A setting's value as the screen says it: a switch as on or off, who is
 * required by name, a trust of zero days as off, and a count of days or codes
 * with its unit.
 *
 * @param settingKey The setting the value belongs to.
 * @param value The value as text, as the row carries it.
 * @returns The value in words.
 */
export function describeHilosSecondFactorSetting(
  settingKey: string,
  value: string,
): string {
  if (isHilosSecondFactorSwitch(settingKey)) {
    return value === SWITCH_ON ? 'On' : 'Off'
  }
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
  readonly value: Hideable<string>
  /** The catalog default, as text. */
  readonly defaultValue: Hideable<string>
  /** For a switch, whether it is on; false for the other six. */
  readonly enabled: Hideable<boolean>
}

// Wire keys: the framework two-step verification table, its inline row slot, and
// the two writes. A project binds its backend to these (Hilos::TABLES / PAGE_TABLES).
const TWO_FACTOR_TABLE = 'hilosSecurityTwoFactor'
const TWO_FACTOR_SLOT = 'setting'
const SETTING_SET_ACTION = 'security_2fa_setting_set'
const SWITCH_SET_ACTION = 'security_2fa_switch_set'

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

/** The writes a two-step verification view binds to. */
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
  /**
   * Set one of the two switches, as a tracked action. The backend refuses a key
   * that is not a switch on the action's `::fail`, and turning the factor off
   * from a browser without a fresh confirmation of the administrator; the row
   * redraws when the setting is written.
   *
   * @param settingKey The switch to set.
   * @param enabled The position to set it to.
   */
  switchSet(settingKey: string, enabled: boolean): ActionHandle
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
  const rowKey =
    readString(slot, HilosTwoFactorSettingRowKey.rowKey) || String(row.rowKey)
  const value = readHideableString(slot, HilosTwoFactorSettingRowKey.value)

  return {
    rowKey,
    value,
    defaultValue: readHideableString(
      slot,
      HilosTwoFactorSettingRowKey.defaultValue,
    ),
    enabled: isHiddenValue(value)
      ? HIDDEN_VALUE
      : isHilosSecondFactorSwitch(rowKey) && value === SWITCH_ON,
  }
}

/** Every row but the first, locked while the factor is off. */
const LOCKED_WHEN_OFF: readonly string[] = Object.values(
  HilosSecondFactorSettingKey,
).filter((settingKey) => settingKey !== HilosSecondFactorSettingKey.enabled)

/** No row locked. */
const NONE_LOCKED: readonly string[] = []

/** The two-step verification table handle a view drives: the controller and the lifecycle. */
export interface HilosTwoFactorTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosTwoFactorSettingRow>
  /**
   * The keys of the rows that are locked, live: every row below the first
   * while the first says the factor is off — the row dimmed, its pencil or
   * switch locked (HIL-1303). Empty while it is on, and while that row is not
   * shown or hidden from a viewer.
   */
  readonly locked: ReadonlySignal<readonly string[]>
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
 * there are eight rows. No title: the page heading already names it.
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
    sendFocus: (rowKey) =>
      context.connection.sendTableRowFocus(
        HilosPages.SECURITY_2FA,
        TWO_FACTOR_TABLE,
        rowKey,
      ),
    frame: TWO_FACTOR_FRAME,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    locked: computedSignal(() =>
      controller.rows
        .get()
        .find((entry) => entry.rowKey === HilosSecondFactorSettingKey.enabled)
        ?.row?.value === SWITCH_OFF
        ? LOCKED_WHEN_OFF
        : NONE_LOCKED,
    ),
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
 * The two-step verification writes: tracked actions over the lifecycle. A
 * handle's `done` resolves on the backend's `::success` and rejects on `::fail`
 * — the modal keeps the value and shows the refusal; a switch goes back.
 *
 * @param context The project context (the action lifecycle the writes dispatch over).
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
    switchSet(settingKey, enabled) {
      return context.actions.dispatch(SWITCH_SET_ACTION, {
        key: settingKey,
        enabled,
      })
    },
  }
}
