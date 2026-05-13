import type { BrowserPageRow } from '@hilos/sdk/types'

export const BROWSER_PAGE_HILOS_SETTINGS = 'subscription_page_hilos_settings'
export const BROWSER_TABLE_SETTINGS = 'settings'
export const BROWSER_SOURCE_SETTINGS = 'settings'

export type SettingValueSource = 'default' | 'reference' | 'override' | 'orphan'

export interface SettingEntity {
  id: number | null
  key: string
  type: string
  value: string | null
  override_value: string | null
  default_value: string | null
  default_reference_key: string | null
  value_source: SettingValueSource
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const rows = (rowsByKey: Record<string, BrowserPageRow> | undefined): BrowserPageRow[] => {
  return rowsByKey === undefined ? [] : Object.values(rowsByKey)
}

const nullableString = (value: unknown): string | null | undefined => {
  if (value === null) return null
  return typeof value === 'string' ? value : undefined
}

const nullableNumber = (value: unknown): number | null | undefined => {
  if (value === null) return null
  return typeof value === 'number' ? value : undefined
}

const isSettingValueSource = (value: unknown): value is SettingValueSource => {
  return value === 'default' || value === 'reference' || value === 'override' || value === 'orphan'
}

export const settingFromBrowserRow = (row: BrowserPageRow | undefined): SettingEntity | null => {
  if (row === undefined || !isRecord(row.sources)) {
    return null
  }

  const setting = row.sources[BROWSER_SOURCE_SETTINGS]
  if (
    !isRecord(setting)
    || nullableNumber(setting.id) === undefined
    || typeof setting.key !== 'string'
    || typeof setting.type !== 'string'
    || nullableString(setting.value) === undefined
    || nullableString(setting.override_value) === undefined
    || nullableString(setting.default_value) === undefined
    || nullableString(setting.default_reference_key) === undefined
    || !isSettingValueSource(setting.value_source)
  ) {
    return null
  }

  return {
    id: nullableNumber(setting.id) ?? null,
    key: setting.key,
    type: setting.type,
    value: nullableString(setting.value) ?? null,
    override_value: nullableString(setting.override_value) ?? null,
    default_value: nullableString(setting.default_value) ?? null,
    default_reference_key: nullableString(setting.default_reference_key) ?? null,
    value_source: setting.value_source,
  }
}

export const settingsFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): SettingEntity[] => {
  return rows(rowsByKey)
    .map(settingFromBrowserRow)
    .filter((setting): setting is SettingEntity => setting !== null)
}

export const isOrphanSetting = (setting: Pick<SettingEntity, 'value_source'>): boolean => {
  return setting.value_source === 'orphan'
}

export const catalogKeysFromSettings = (settings: SettingEntity[]): string[] => {
  return settings
    .filter((setting) => !isOrphanSetting(setting))
    .map((setting) => setting.key)
}

export const availableCatalogKeysFromSettings = (settings: SettingEntity[]): string[] => {
  return settings
    .filter((setting) => setting.id === null && !isOrphanSetting(setting))
    .map((setting) => setting.key)
}
