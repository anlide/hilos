// The framework Hilos settings admin headless: the cataloged-settings view-model,
// the row resolver, and the table / actions factories the per-framework
// HilosSettingsPage view renders from. It is framework-agnostic (imports no UI
// framework) and reads only @hilos/core primitives, so the Vue/React/Angular
// settings views stay thin (multiframework-core.md).
//
// The page is generic: its identity, view-model, table behavior, and the
// add / update / delete round-trips are the framework's; the actual catalog of
// settings is the project's, declared on its backend (the SettingsCatalog) and
// delivered through the page-scoped `settings` table. A project supplies a
// HilosSettingsContext — its scope manager and its connection — and the framework
// owns the rest.

import {
  ActionLifecycle,
  type ActionHandle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readString, readStringOrNull } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** Where a setting's effective value comes from. */
export type SettingValueSource = 'default' | 'reference' | 'override' | 'orphan'

const VALUE_SOURCES: readonly SettingValueSource[] = [
  'default',
  'reference',
  'override',
  'orphan',
]

/** One row of the Hilos settings table — the framework settings view-model. */
export interface HilosSettingRow {
  /** The setting key; also the table row key. */
  readonly key: string
  /** Value type: `string` | `integer` | `float` | `boolean`. */
  readonly type: string
  /** Effective value (override when set, else the resolved default), serialized. */
  readonly value: string | null
  /** The persisted override value, or null when on the catalog default. */
  readonly overrideValue: string | null
  /** The catalog default value, serialized; null for an orphan. */
  readonly defaultValue: string | null
  /** The key this setting's default references, when the default is a reference. */
  readonly defaultReferenceKey: string | null
  /** Where the effective value comes from. */
  readonly valueSource: SettingValueSource
}

// Wire keys: the framework settings table and its single inline `settings` slot
// (the catalog-merged fields) and the add / update / delete action names. A
// project binds its backend to these keys.
const SETTINGS_TABLE = 'settings'
const SETTINGS_SLOT = 'settings'
const SETTINGS_PAGE_SIZE = 10
const SETTING_ADD_ACTION = 'setting_add'
const SETTING_UPDATE_ACTION = 'setting_update'
const SETTING_DELETE_ACTION = 'setting_delete'

/**
 * The project-supplied context the settings admin reads from: the scope-partitioned
 * stores that own the page-scoped settings table, and the action lifecycle the
 * mutations dispatch over. Everything else (the table key, slot name, view-model,
 * and behavior) is the framework's; the catalog content is the project's backend.
 */
export interface HilosSettingsContext {
  /** The connection the table sends its viewport over and receives its window / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scope the table window normalizes into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the add / update / delete tracked actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The settings mutation surface a settings view binds to. */
export interface HilosSettingsActions {
  /**
   * Create a custom value for a catalog setting, as a tracked action.
   *
   * @param key The setting key.
   * @param value The custom value to persist.
   */
  sendSettingAdd(key: string, value: string): ActionHandle
  /**
   * Update a persisted setting's value, as a tracked action. A null value resets
   * it to the catalog default.
   *
   * @param key The setting key.
   * @param value The new value, or null to reset to the catalog default.
   */
  sendSettingUpdate(key: string, value: string | null): ActionHandle
  /**
   * Delete an orphan setting (a key not in the catalog), as a tracked action.
   *
   * @param key The setting key.
   */
  sendSettingDelete(key: string): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** Narrow an unknown value_source to the typed union, defaulting to orphan. */
function toValueSource(value: unknown): SettingValueSource {
  return VALUE_SOURCES.includes(value as SettingValueSource)
    ? (value as SettingValueSource)
    : 'orphan'
}

/**
 * Resolve one raw settings table row into its view-model. The catalog-merged
 * fields ride a single inline `settings` slot (no entity reference — a setting is
 * page-scoped, keyed by its key), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosSettingRow(row: TableRow): HilosSettingRow {
  const slot = recordSlot(row.slots[SETTINGS_SLOT]) ?? {}

  return {
    key: readString(slot, 'key') || String(row.rowKey),
    type: readString(slot, 'type'),
    value: readStringOrNull(slot, 'value'),
    overrideValue: readStringOrNull(slot, 'override_value'),
    defaultValue: readStringOrNull(slot, 'default_value'),
    defaultReferenceKey: readStringOrNull(slot, 'default_reference_key'),
    valueSource: toValueSource(slot['value_source']),
  }
}

/** A setting whose key is not in the catalog — the only deletable kind. */
export function isOrphanSetting(row: HilosSettingRow): boolean {
  return row.valueSource === 'orphan'
}

/** A setting backed by a persisted DB row (a custom override or an orphan). */
export function isPersistedSetting(row: HilosSettingRow): boolean {
  return row.valueSource === 'override' || row.valueSource === 'orphan'
}

/** The settings table handle a settings view drives: the controller plus its mount lifecycle. */
export interface HilosSettingsTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosSettingRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The server-windowed controller for the Hilos settings table: search, sort, and
 * paging change the viewport descriptor sent over the connection, and the backend
 * replies a window plus live deltas scoped to the table's (page, tableKey)
 * address. Rows resolve through {@link resolveHilosSettingRow}. The returned
 * handle's `start` binds the table to its address and requests the first window;
 * `dispose` unbinds it (the view calls them on mount / unmount).
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosSettingsTable(
  context: HilosSettingsContext,
): HilosSettingsTable {
  const controller = new TableViewportController<HilosSettingRow>({
    resolve: resolveHilosSettingRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.SETTINGS,
        SETTINGS_TABLE,
        descriptor,
      ),
    pageSize: SETTINGS_PAGE_SIZE,
    initialSort: { field: 'key', direction: 'asc' },
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.SETTINGS, tableKey: SETTINGS_TABLE },
          controller,
        ),
        // Re-request the window whenever the socket (re)connects: the initial
        // request below can run before the connection is open, and a reconnect
        // is a fresh exchange that no longer remembers this connection's window.
        context.connection.on('state', (state) => {
          if (state === 'connected') {
            controller.start()
          }
        }),
      )
      controller.start()
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The settings mutation surface: the add / update / delete submits as tracked
 * actions over the lifecycle. Each returns an ActionHandle whose `done` resolves
 * on the backend's `::success` ack and rejects on `::fail` — the view closes the
 * dialog on success and surfaces the failure (authoritative-backend). The
 * committed row returns separately over the live settings table.
 *
 * @param context The project context (the action lifecycle the actions dispatch over).
 */
export function createHilosSettingsActions(
  context: HilosSettingsContext,
): HilosSettingsActions {
  return {
    sendSettingAdd(key, value) {
      return context.actions.dispatch(SETTING_ADD_ACTION, { key, value })
    },
    sendSettingUpdate(key, value) {
      return context.actions.dispatch(SETTING_UPDATE_ACTION, { key, value })
    },
    sendSettingDelete(key) {
      return context.actions.dispatch(SETTING_DELETE_ACTION, { key })
    },
  }
}
