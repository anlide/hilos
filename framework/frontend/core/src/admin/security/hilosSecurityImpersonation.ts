// The framework Hilos impersonation settings admin headless (HIL-1170): the
// settings view-model, its row resolver, and the table / actions factories the
// per-framework HilosSecurityImpersonationPage views render from.
// Framework-agnostic (imports no UI framework), so the three views stay thin
// (multiframework-core.md).
//
// The rows come from the settings in force: one per impersonation setting, its
// value and the catalog default as text — `true` / `false` for a switch, the
// scope value for the scope. A row changes when the setting does, whichever door
// wrote it, so the screen needs no signal of its own. The six switches write at
// once (a mutation without a parameter); the scope is a choice and is edited in
// a modal (the modal-only editing rule).

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
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/**
 * The seven impersonation settings, by the key the setting is stored under
 * (PHP `ImpersonationSettings::*_KEY`), in the order the screen lists them.
 */
export const HilosImpersonationSettingKey = {
  allowed: 'auth.impersonation.allowed',
  scope: 'auth.impersonation.scope',
  accountAccess: 'auth.impersonation.account_access',
  carryAdmin: 'auth.impersonation.carry_admin',
  blocked: 'auth.impersonation.blocked',
  frozen: 'auth.impersonation.frozen',
  equal: 'auth.impersonation.equal',
} as const

/** What may be done inside someone else's account — the values of the scope setting. */
export const HILOS_IMPERSONATION_SCOPE_VALUES = ['view', 'act'] as const

/** One value of the scope setting. */
export type HilosImpersonationScope =
  (typeof HILOS_IMPERSONATION_SCOPE_VALUES)[number]

/** The text a switch row carries when it is on. */
const SWITCH_ON = 'true'

/** What the screen says about one setting: its name and the line under it. */
export interface HilosImpersonationSettingCopy {
  /** The setting's name on the screen. */
  readonly label: string
  /** What the setting decides. */
  readonly hint: string
}

/**
 * The words of the seven settings, by key. Framework copy rather than each
 * view's own: three view layers draw the same rows, and copies drift.
 */
export const HILOS_IMPERSONATION_SETTING_COPY: Readonly<
  Record<string, HilosImpersonationSettingCopy>
> = {
  [HilosImpersonationSettingKey.allowed]: {
    label: 'Impersonation is allowed',
    hint: 'Switch it off and the product has no impersonation at all.',
  },
  [HilosImpersonationSettingKey.scope]: {
    label: "What may be done in someone else's account",
    hint: 'Only look, or act as well.',
  },
  [HilosImpersonationSettingKey.accountAccess]: {
    label: 'Allow touching sign-in',
    hint: 'Password, ways to sign in, second factor, sessions — otherwise impersonation can take the account away for good.',
  },
  [HilosImpersonationSettingKey.carryAdmin]: {
    label: 'Carry your admin rights',
    hint: "By default the administrator sees the product through the person's eyes, without their own privileges.",
  },
  [HilosImpersonationSettingKey.blocked]: {
    label: 'Impersonate a blocked person',
    hint: 'For service work; the strip turns red.',
  },
  [HilosImpersonationSettingKey.frozen]: {
    label: 'Impersonate a frozen person',
    hint: 'Separate from blocking: a freeze is not a punishment.',
  },
  [HilosImpersonationSettingKey.equal]: {
    label: 'Impersonate an equal',
    hint: 'Another administrator: with the same access, impersonation gives nothing new.',
  },
}

/** The scope values as the screen names them. */
export const HILOS_IMPERSONATION_SCOPE_COPY: Readonly<
  Record<HilosImpersonationScope, string>
> = {
  view: 'View only',
  act: 'View and act',
}

/** The line under the choice in the scope modal. */
export const HILOS_IMPERSONATION_SCOPE_HINT =
  'Only look, or act as well. Default: view and act.'

/**
 * Whether a setting is one of the six switches; the scope is the one that is
 * not.
 *
 * @param settingKey The setting key.
 * @returns True for a yes-or-no setting.
 */
export function isHilosImpersonationSwitch(settingKey: string): boolean {
  return settingKey !== HilosImpersonationSettingKey.scope
}

/** One row of the impersonation table — the framework settings view-model. */
export interface HilosImpersonationSettingRow {
  /** The setting key the row stands for; also the table row key. */
  readonly rowKey: string
  /** The value in force, as text. */
  readonly value: Hideable<string>
  /** The catalog default, as text. */
  readonly defaultValue: Hideable<string>
  /** For a switch, whether it is on; false for the scope. */
  readonly enabled: Hideable<boolean>
}

// Wire keys: the framework impersonation table, its inline row slot, and the two
// actions. A project binds its backend to these (Hilos::TABLES / PAGE_TABLES).
const IMPERSONATION_TABLE = 'hilosSecurityImpersonation'
const IMPERSONATION_SLOT = 'setting'
const SWITCH_SET_ACTION = 'security_impersonation_switch_set'
const SCOPE_SET_ACTION = 'security_impersonation_scope_set'

/**
 * Payload keys of the impersonation row slot (mirrors the backend row shape,
 * PHP `HilosSecurityImpersonationTableRow`).
 */
export const HilosImpersonationSettingRowKey = {
  rowKey: 'rowKey',
  value: 'value',
  defaultValue: 'defaultValue',
} as const

/**
 * The project-supplied context the impersonation admin reads from: the
 * scope-partitioned stores that own the page-scoped table, the connection the
 * table window rides, and the action lifecycle the writes dispatch over.
 */
export interface HilosImpersonationContext {
  /** The connection the table sends its viewport over and receives windows / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope of the table. */
  readonly scopes: ScopeManager
  /** The action lifecycle the tracked writes dispatch over. */
  readonly actions: ActionLifecycle
}

/** The writes an impersonation view binds to. */
export interface HilosImpersonationActions {
  /**
   * Switch one of the six yes-or-no settings, as a tracked action. The backend
   * refuses a key that is not a switch on the action's `::fail`; the row redraws
   * when the setting is written.
   *
   * @param settingKey The switch to set.
   * @param enabled The position to set it to.
   */
  sendSwitchSet(settingKey: string, enabled: boolean): ActionHandle
  /**
   * Set what may be done inside someone else's account, as a tracked action. A
   * value the setting's rule refuses comes back on `::fail`.
   *
   * @param scope The scope chosen.
   */
  sendScopeSet(scope: HilosImpersonationScope): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/**
 * Resolve one raw impersonation row into its view-model. The setting rides a
 * single inline `setting` slot, keyed by the setting key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosImpersonationSettingRow(
  row: TableRow,
): HilosImpersonationSettingRow {
  const slot = recordSlot(row.slots[IMPERSONATION_SLOT]) ?? {}
  const rowKey =
    readString(slot, HilosImpersonationSettingRowKey.rowKey) ||
    String(row.rowKey)
  const value = readHideableString(slot, HilosImpersonationSettingRowKey.value)

  return {
    rowKey,
    value,
    defaultValue: readHideableString(
      slot,
      HilosImpersonationSettingRowKey.defaultValue,
    ),
    enabled: isHiddenValue(value)
      ? HIDDEN_VALUE
      : isHilosImpersonationSwitch(rowKey) && value === SWITCH_ON,
  }
}

/**
 * The scope a row carries, as one of the two values; anything else reads as the
 * default, view and act.
 *
 * @param row The scope row.
 * @returns The scope in force.
 */
export function hilosImpersonationScopeOf(
  row: HilosImpersonationSettingRow,
): HilosImpersonationScope {
  return row.value === 'view' ? 'view' : 'act'
}

/** The impersonation table handle a view drives: the controller and the lifecycle. */
export interface HilosImpersonationTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosImpersonationSettingRow>
  /** Bind the table to the connection — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/** The columns of the impersonation table, in display order. */
const IMPERSONATION_COLUMNS: HilosTableColumnOf<HilosImpersonationSettingRow>[] =
  [
    {
      key: HilosImpersonationSettingRowKey.rowKey,
      label: 'Setting',
    },
    {
      // A switch for a yes-or-no setting, the value in words for the scope.
      key: HilosImpersonationSettingRowKey.value,
      label: 'Value',
      reads: [HilosImpersonationSettingRowKey.defaultValue],
    },
    {
      // The pencil that opens the scope's modal (the modal-only editing rule).
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      headerClass: 'text-end',
      cellClass: 'text-end',
      reads: [HilosImpersonationSettingRowKey.value],
    },
  ]

/**
 * What the impersonation table declares about its frame. No search: there are
 * seven rows. No title: the page heading already names it.
 */
const IMPERSONATION_FRAME: HilosTableFrame = {
  columns: IMPERSONATION_COLUMNS,
  empty: { title: 'No impersonation settings.' },
}

/**
 * The server-windowed controller for the impersonation table. Rows resolve
 * through {@link resolveHilosImpersonationSettingRow}.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosSecurityImpersonationTable(
  context: HilosImpersonationContext,
): HilosImpersonationTable {
  const controller = new TableViewportController<HilosImpersonationSettingRow>({
    resolve: resolveHilosImpersonationSettingRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.SECURITY_IMPERSONATION,
        IMPERSONATION_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.SECURITY_IMPERSONATION,
        IMPERSONATION_TABLE,
        rendered,
      ),
    // The scope's modal holds its row in focus while it is open, so the row it
    // merges against follows the server wherever it goes (rowEdit.ts).
    sendFocus: (rowKey) =>
      context.connection.sendTableRowFocus(
        HilosPages.SECURITY_IMPERSONATION,
        IMPERSONATION_TABLE,
        rowKey,
      ),
    frame: IMPERSONATION_FRAME,
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
            page: HilosPages.SECURITY_IMPERSONATION,
            tableKey: IMPERSONATION_TABLE,
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

/**
 * The impersonation writes: tracked actions over the lifecycle. A switch is
 * answered by the row moving; the scope's handle resolves on `::success` and
 * rejects on `::fail` — the modal keeps the choice and shows the refusal.
 *
 * @param context The project context (the action lifecycle the writes dispatch over).
 */
export function createHilosSecurityImpersonationActions(
  context: Pick<HilosImpersonationContext, 'actions'>,
): HilosImpersonationActions {
  return {
    sendSwitchSet(settingKey, enabled) {
      return context.actions.dispatch(SWITCH_SET_ACTION, {
        key: settingKey,
        enabled,
      })
    },
    sendScopeSet(scope) {
      return context.actions.dispatch(SCOPE_SET_ACTION, { scope })
    },
  }
}
