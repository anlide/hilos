// The Hilos settings table row view-model: one row of the settings catalog as the
// page renders it (data-model.md — table-row view-models live in types/tables/).
// The backend merges the catalog with persisted overrides into a single inline
// `settings` slot per row; the selector that resolves it lives with the page
// (settingsPage.ts), not here. There is no entity reference — a setting is
// page-scoped, keyed by its setting key, not a reusable cross-scope entity.

/** Where a setting's effective value comes from. */
export type SettingValueSource = 'default' | 'reference' | 'override' | 'orphan'

/** One row of the Hilos settings table. */
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
