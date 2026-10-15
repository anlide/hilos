import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from './hilosI18nCountry.js'

/** Tracked page action that switches off one country. */
export const HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION =
  'hilos_i18n_country_switch_off'

/** Tracked page action that switches on one country. */
export const HILOS_I18N_COUNTRY_SWITCH_ON_ACTION =
  'hilos_i18n_country_switch_on'

/** Words shared by the country switch-off control and its confirmation window. */
export const HILOS_I18N_COUNTRY_SWITCH_OFF_COPY = {
  open: 'Switch off',
  title: (label: string) => `Switch off country · ${label}`,
  body: 'Switching a country off is the first step of editing it: while it is off, nothing keeps its fields frozen.',
  localeCount: 'Locales of this country:',
  nameCount: 'names:',
  note: 'Locales and names stay in place and come back with the country.',
  cancel: 'Cancel',
  confirm: 'Switch off',
  refusalTitle: "Couldn't switch off the country",
  elsewhere: 'Switched off elsewhere just now.',
} as const

/** Words shared by the country switch-on control and its confirmation window. */
export const HILOS_I18N_COUNTRY_SWITCH_ON_COPY = {
  open: 'Switch on',
  title: (label: string) => `Switch on country · ${label}`,
  body: 'Switching a country on freezes its fields for everyone: to edit them again, switch it off first.',
  note: {
    missing:
      'No default locale is chosen: formats will come from the language, and that is allowed. One can be chosen later, while the country is off.',
    off: (code: string) =>
      `Its default locale ${code} is switched off: until that locale is switched on, formats will come from the language. This does not block switching on.`,
  },
  cancel: 'Cancel',
  confirm: 'Switch on',
  refusalTitle: "Couldn't switch on the country",
  elsewhere: 'Switched on elsewhere just now.',
} as const

/**
 * Choose the country label for dialog titles: the localized name if present,
 * otherwise uppercase country code.
 *
 * @param card The country card.
 */
export function hilosI18nCountryLabel(card: HilosI18nCountryCard): string {
  return card.summary.name ?? card.code.toUpperCase()
}

/**
 * Decide whether the live card offers switch-on and assemble its default-locale note.
 *
 * @param card The country card, or null when the page source has disappeared.
 */
export function hilosI18nCountrySwitchOn(card: HilosI18nCountryCard | null): {
  shown: boolean
  defaultLocale: 'missing' | 'off' | 'on'
  note: string | null
} {
  if (card === null) {
    return {
      shown: false,
      defaultLocale: 'missing',
      note: null,
    }
  }

  const defaultLocale = card.summary.defaultLocale
  let note: string | null = null
  if (defaultLocale === 'missing') {
    note = HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.missing
  } else if (defaultLocale === 'off') {
    note = HILOS_I18N_COUNTRY_SWITCH_ON_COPY.note.off(
      card.summary.defaultLocaleCode ?? '',
    )
  }

  return {
    shown: card.enabled === false,
    defaultLocale,
    note,
  }
}

/**
 * The tracked switch-off operation bound to the project action lifecycle.
 *
 * @param context The country page context providing the action dispatcher.
 */
export function createHilosI18nCountrySwitchOff(
  context: HilosI18nCountryContext,
): { switchOff(countryId: number): ActionHandle } {
  return {
    switchOff: (countryId) =>
      context.actions.dispatch(HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION, {
        countryId,
      }),
  }
}

/**
 * The tracked switch-on operation bound to the project action lifecycle.
 *
 * @param context The country page context providing the action dispatcher.
 */
export function createHilosI18nCountrySwitchOn(
  context: HilosI18nCountryContext,
): { switchOn(countryId: number): ActionHandle } {
  return {
    switchOn: (countryId) =>
      context.actions.dispatch(HILOS_I18N_COUNTRY_SWITCH_ON_ACTION, {
        countryId,
      }),
  }
}
