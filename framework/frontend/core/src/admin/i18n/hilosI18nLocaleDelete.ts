import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'

/** Tracked deletion door on the language's locales page. */
export const HILOS_I18N_LOCALE_DELETE_ACTION = 'hilos_i18n_locale_delete'

/** The identity and labels frozen when a locale's confirmation opens. */
export interface HilosI18nLocaleDeleteTarget {
  readonly languageId: number
  readonly countryId: number | null
  readonly localeId: number
  readonly languageCode: string
  readonly countryCode: string | null
  readonly localeCode: string
  readonly nativeName: string
  readonly countryLabel: string | null
}

/** Shared confirmation copy; the server alone decides whether deletion is allowed. */
export const HILOS_I18N_LOCALE_DELETE_COPY = {
  title: (nativeName: string, countryLabel: string | null) =>
    countryLabel === null
      ? `Delete locale · ${nativeName} without a country`
      : `Delete locale · ${nativeName} in ${countryLabel}`,
  body: (languageCode: string, countryCode: string | null) =>
    countryCode === null
      ? `This removes the seven base formats of ${languageCode}. The language will have no formats of its own without a country and will fall back to the default language.`
      : `This removes the seven formats of this pair. Text in this language and country will use the language's countryless locale — ${languageCode}.`,
  note: 'Switch the locale off before deleting it. If a country uses it as its default, choose another locale for that country first. Remove any language or country name overrides for this locale first; deletion never removes them.',
  cancel: 'Cancel',
  confirm: 'Delete',
  refusalTitle: "Couldn't delete the locale",
  elsewhere: 'Deleted elsewhere.',
} as const

/** Dispatch the captured locale and parent IDs, preserving the countryless null. */
export function createHilosI18nLocaleDelete(
  context: HilosI18nLanguageContext,
): {
  deleteLocale(
    languageId: number,
    countryId: number | null,
    localeId: number,
  ): ActionHandle
} {
  return {
    deleteLocale: (languageId, countryId, localeId) =>
      context.actions.dispatch(HILOS_I18N_LOCALE_DELETE_ACTION, {
        languageId,
        countryId,
        localeId,
      }),
  }
}
