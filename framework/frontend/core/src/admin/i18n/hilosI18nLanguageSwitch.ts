import { type ActionHandle } from '../../connection/actionLifecycle.js'
import {
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from './hilosI18nLanguage.js'

/** Tracked page action that switches off one language. */
export const HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION =
  'hilos_i18n_language_switch_off'

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
