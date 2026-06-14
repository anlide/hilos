// The Hilos settings page selectors: the page-scoped settings table rows fed into
// a headless TableController (client search/sort/paginate), each raw row resolved
// into its HilosSettingRow view-model. The backend merges the catalog with
// persisted overrides into one inline `settings` slot per row (no entity ref —
// settings are page-scoped, keyed by the setting key), so the resolver reads the
// slot as a plain record. The view reads the controller, never a raw store.
import {
  readString,
  readStringOrNull,
  TableController,
  type TableRow,
} from '@hilos/core'

import { scopes } from '../../../bootstrap/session'
import {
  type HilosSettingRow,
  type SettingValueSource,
} from '../../../types/tables/HilosSettingRow'

// Wire keys: the settings table (backend ChatTableContext::settings) and its
// single inline slot (HilosDbContext::settings), carrying the catalog-merged fields.
export const SETTINGS_TABLE = 'settings'
const SETTINGS_SLOT = 'settings'

const VALUE_SOURCES: readonly SettingValueSource[] = [
  'default',
  'reference',
  'override',
  'orphan',
]

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
 * fields ride a single inline `settings` slot (no entity reference), so this
 * reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveSettingRow(row: TableRow): HilosSettingRow {
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
 * Catalog keys currently on their default (no override) — the candidates the Add
 * dialog offers for creating a custom value.
 *
 * @param rows The resolved settings rows.
 */
export function catalogDefaultKeys(rows: readonly HilosSettingRow[]): string[] {
  return rows
    .filter(
      (row) => row.valueSource === 'default' || row.valueSource === 'reference',
    )
    .map((row) => row.key)
}

/** The headless controller for the Hilos settings table (client viewport). */
export const settingsTable = new TableController<HilosSettingRow>({
  source: scopes.pageTableSignal(SETTINGS_TABLE),
  resolve: resolveSettingRow,
  searchText: (row) => `${row.key} ${row.value ?? ''}`,
  sortValue: (row, field) => (field === 'value' ? row.value : row.key),
  initialSort: { field: 'key', direction: 'asc' },
})
