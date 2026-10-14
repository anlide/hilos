import {
  createSignal,
  type TableViewportController,
  type HilosI18nNameRow,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleTemplates,
} from '@hilos/core'
import { vi } from 'vitest'

export const nameRow: HilosI18nNameRow = {
  code: 'en',
  nativeName: 'English',
  name: 'Ukrainian',
  corrections: [
    {
      localeCode: 'en-GB',
      countryCode: 'gb',
      countryName: 'United Kingdom',
      name: 'Correction',
    },
    { localeCode: 'en-US', countryCode: 'us', countryName: null, name: '' },
    {
      localeCode: 'en-CA',
      countryCode: 'ca',
      countryName: 'Canada',
      name: null,
    },
  ],
}
export const formats = {
  date: 'DD/MM/YYYY',
  time: 'HH:mm:ss',
  number: '1,000.00',
  phone: '+XX-XXXX-XXXX',
  address: 'Street, House, City, Index',
  measurement: 'metric' as const,
  collation: 'und',
}
export const templates: HilosI18nLocaleTemplates = {
  date: ['DD/MM/YYYY', 'YYYY-MM-DD', 'DD.MM.YYYY', 'MM/DD/YYYY'],
  time: ['HH:mm:ss'],
  number: ['1,000.00'],
  phone: ['+XX-XXXX-XXXX'],
  address: ['Street, House, City, Index'],
  measurement: ['metric', 'imperial'],
  collation: ['und'],
}
export const localeRow: HilosI18nLocaleRow = {
  rowKey: 'uk-GB',
  countryCode: 'gb',
  countryName: 'United Kingdom',
  localeCode: 'uk-GB',
  enabled: false,
  isCountryDefault: false,
  formats,
  catalogFormats: formats,
}
export const countryless: HilosI18nLocaleRow = {
  ...localeRow,
  rowKey: 'uk',
  countryCode: null,
  countryName: null,
  localeCode: 'uk',
}
export const absentLocale: HilosI18nLocaleRow = {
  ...localeRow,
  localeCode: null,
  formats: null,
  enabled: null,
}

/** A window test supplies only its focus source; actions use the real lifecycle. */
export function focusSource<R>(initial: R) {
  let next: R | null = initial
  const focused = createSignal<R | undefined>(undefined)
  const releaseFocus = vi.fn(() => focused.set(undefined))
  const focusRow = vi.fn(() => {
    focused.set(next ?? undefined)
    return next
  })
  const controller = {
    focusedRow: focused,
    focusRow,
    releaseFocus,
  } as unknown as TableViewportController<R>
  return {
    controller,
    focused,
    releaseFocus,
    focusRow,
    fresh(value: R | null) {
      next = value
    },
  }
}
