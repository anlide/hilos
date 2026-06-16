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
import { readString, readStringOrNull } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { TableController } from '../../table/TableController.js'

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
  /** The scope manager that owns the page-scoped settings table rows. */
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

/**
 * The headless controller for the Hilos settings table (client viewport): search
 * by key or value, sort by key or value. Rows resolve through
 * {@link resolveHilosSettingRow}.
 *
 * @param context The project context (its scope stores).
 */
export function createHilosSettingsTable(
  context: HilosSettingsContext,
): TableController<HilosSettingRow> {
  return new TableController<HilosSettingRow>({
    source: context.scopes.pageTableSignal(SETTINGS_TABLE),
    resolve: resolveHilosSettingRow,
    searchText: (row) => `${row.key} ${row.value ?? ''}`,
    sortValue: (row, field) => (field === 'value' ? row.value : row.key),
    initialSort: { field: 'key', direction: 'asc' },
  })
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
