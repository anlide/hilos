import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from './hilosI18nLanguage.js'

/** Tracked page action that switches off one language. */
export const HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION =
  'hilos_i18n_language_switch_off'

/** Tracked page action that switches on one language. */
export const HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION =
  'hilos_i18n_language_switch_on'

/** Words shared by the language switch-off control and its confirmation window. */
export const HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY = {
  open: 'Switch off',
  title: (nativeName: string) => `Switch off language · ${nativeName}`,
  body: 'Switching a language off is the first step of editing it: while it is off, nothing keeps its fields frozen.',
  note: 'Translations, locales and names stay in place and come back with the language.',
  cancel: 'Cancel',
  confirm: 'Switch off',
  refusalTitle: "Couldn't switch off the language",
  defaultReason:
    'The default language is set in env and cannot be switched off.',
  elsewhere: 'Switched off elsewhere just now.',
} as const

/** Words shared by the language switch-on control and its confirmation window. */
export const HILOS_I18N_LANGUAGE_SWITCH_ON_COPY = {
  open: 'Switch on',
  title: (nativeName: string) => `Switch on language · ${nativeName}`,
  body: 'Switching a language on freezes its fields for everyone: to edit them again, switch it off first. Nothing checks that its translation is complete.',
  localeLabel: 'Locale without a country:',
  locale: {
    missing: 'not created',
    off: 'switched off',
    on: 'switched on',
  },
  namesLabel: 'Names in other languages:',
  warning: {
    missing:
      'A switched-on language should have a locale without a country: it gives the language its own dates and numbers. This does not block switching on, but create that locale first.',
    off: 'A switched-on language should have its locale without a country switched on: it gives the language its own dates and numbers. This does not block switching on, but switch that locale on first.',
  },
  cancel: 'Cancel',
  confirm: 'Switch on',
  refusalTitle: "Couldn't switch on the language",
  elsewhere: 'Switched on elsewhere just now.',
} as const

/**
 * Decide whether the live card offers switch-off and whether the default forbids it.
 *
 * @param card The language card, or null when the page source has disappeared.
 */
export function hilosI18nLanguageSwitchOff(
  card: HilosI18nLanguageCard | null,
): { shown: boolean; disabled: boolean; reason: string | null } {
  const shown = card?.enabled === true
  const disabled = shown && card.summary.isDefault
  return {
    shown,
    disabled,
    reason: disabled ? HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY.defaultReason : null,
  }
}

/** The tracked switch-off operation bound to the project action lifecycle. */
export function createHilosI18nLanguageSwitchOff(
  context: HilosI18nLanguageContext,
): { switchOff(languageCode: string): ActionHandle } {
  return {
    switchOff: (languageCode) =>
      context.actions.dispatch(HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION, {
        languageCode,
      }),
  }
}

/**
 * Read the live facts and warning for switching on a language.
 *
 * @param card The language card, or null when the page source has disappeared.
 */
export function hilosI18nLanguageSwitchOn(card: HilosI18nLanguageCard | null): {
  shown: boolean
  locale: 'missing' | 'off' | 'on'
  warning: string | null
  nameCount: number
} {
  if (card === null) {
    return { shown: false, locale: 'missing', warning: null, nameCount: 0 }
  }
  const locale = card.summary.countrylessLocale
  return {
    shown: card.enabled === false,
    locale,
    warning:
      locale === 'on'
        ? null
        : HILOS_I18N_LANGUAGE_SWITCH_ON_COPY.warning[locale],
    nameCount: card.summary.nameCount,
  }
}

/** The tracked switch-on operation bound to the project action lifecycle. */
export function createHilosI18nLanguageSwitchOn(
  context: HilosI18nLanguageContext,
): { switchOn(languageCode: string): ActionHandle } {
  return {
    switchOn: (languageCode) =>
      context.actions.dispatch(HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION, {
        languageCode,
      }),
  }
}
