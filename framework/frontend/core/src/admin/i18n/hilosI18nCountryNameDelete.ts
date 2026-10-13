import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { type HilosI18nCountryContext } from './hilosI18nCountry.js'

/** Tracked actions of the country names page. */
export const HILOS_I18N_COUNTRY_NAME_DELETE_ACTION =
  'hilos_i18n_country_name_delete'
export const HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE_ACTION =
  'hilos_i18n_country_name_override_delete'

/** The coordinates and literal value confirmed when the window opens. */
export type HilosI18nCountryNameDeleteTarget = {
  readonly countryCode: string
  readonly countryName: string
  readonly inLanguageCode: string
  readonly inLanguageNativeName: string
  readonly expectedName: string
} & (
  | { readonly kind: 'base'; readonly localeCode?: never }
  | { readonly kind: 'override'; readonly localeCode: string }
)

/** Shared English copy for the controls and the two confirmation views. */
export const HILOS_I18N_COUNTRY_NAME_DELETE_COPY = {
  baseLabel: (nativeName: string) => `Delete country name in ${nativeName}`,
  overrideLabel: (localeCode: string) =>
    `Remove locale correction ${localeCode}`,
  title: (target: HilosI18nCountryNameDeleteTarget) =>
    target.kind === 'base'
      ? `Delete name · ${target.countryName} in ${target.inLanguageNativeName}`
      : `Remove correction · ${target.countryName} in ${target.localeCode}`,
  baseBody:
    'The country, languages, locales and names in other languages stay.',
  cascade: 'All locale corrections of this base name will also be deleted.',
  overrideBody:
    'Only this locale correction will be removed. The locale will inherit the current base name.',
  overrideNote:
    'The catalog supplies only base names. This correction will not return by itself.',
  current: 'Value being removed:',
  catalog:
    'If the built-in catalog knows this name, it returns immediately as an unlocked name. Otherwise, there will be no name in this language.',
  inherited: 'Base name to inherit:',
  empty: 'Empty value',
  emptyDescription: 'A saved empty string, not an absent name.',
  missing: 'The name no longer exists.',
  cancel: 'Cancel',
  delete: 'Delete',
  remove: 'Remove',
  refusalTitle: "Couldn't delete the country name",
} as const

/** Bind both deletions to the tracked dispatcher, leaving all data to the subscription. */
export function createHilosI18nCountryNameDelete(
  context: HilosI18nCountryContext,
): { deleteName(target: HilosI18nCountryNameDeleteTarget): ActionHandle } {
  return {
    deleteName: (target) => {
      const payload = {
        countryCode: target.countryCode,
        inLanguageCode: target.inLanguageCode,
        expectedName: target.expectedName,
      }
      return target.kind === 'base'
        ? context.actions.dispatch(
            HILOS_I18N_COUNTRY_NAME_DELETE_ACTION,
            payload,
          )
        : context.actions.dispatch(
            HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE_ACTION,
            {
              ...payload,
              localeCode: target.localeCode,
            },
          )
    },
  }
}
