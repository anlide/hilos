import {
  createSignal,
  type TableViewportController,
  type HilosI18nNameRow,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleTemplates,
} from '@hilos/core'
import { vi } from 'vitest'

export const nameRow: HilosI18nNameRow = {
  inLanguageId: 11,
  nameId: 301,
  rowKey: '11',
  code: 'en',
  nativeName: 'English',
  name: 'Ukrainian',
  corrections: [
    {
      localeId: 501,
      nameId: 401,
      countryId: 101,
      localeCode: 'en-GB',
      countryCode: 'gb',
      countryName: 'United Kingdom',
      name: 'Correction',
    },
    {
      localeId: 502,
      nameId: 402,
      countryId: 102,
      localeCode: 'en-US',
      countryCode: 'us',
      countryName: null,
      name: '',
    },
    {
      localeId: 503,
      nameId: null,
      countryId: 103,
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
  languageId: 19,
  countryId: 101,
  localeId: 19101,
  rowKey: '19:101',
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
  languageId: 19,
  countryId: null,
  localeId: 19000,
  rowKey: '19:null',
  countryCode: null,
  countryName: null,
  localeCode: 'uk',
}
export const absentLocale: HilosI18nLocaleRow = {
  ...localeRow,
  localeId: null,
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
