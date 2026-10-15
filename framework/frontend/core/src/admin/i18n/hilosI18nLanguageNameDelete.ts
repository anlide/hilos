import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'

/** Tracked actions of the language names page. */
export const HILOS_I18N_LANGUAGE_NAME_DELETE_ACTION =
  'hilos_i18n_language_name_delete'
export const HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE_ACTION =
  'hilos_i18n_language_name_override_delete'

/** The coordinates and literal value confirmed when the window opens. */
export type HilosI18nLanguageNameDeleteTarget = {
  readonly languageId: number
  readonly inLanguageId: number
  readonly nameId: number
  readonly languageCode: string
  readonly languageNativeName: string
  readonly inLanguageCode: string
  readonly inLanguageNativeName: string
  readonly expectedName: string
} & (
  | {
      readonly kind: 'base'
      readonly localeCode?: never
      readonly localeId?: never
    }
  | {
      readonly kind: 'override'
      readonly localeCode: string
      readonly localeId: number
    }
)

/** Shared English copy for the controls and the two confirmation views. */
export const HILOS_I18N_LANGUAGE_NAME_DELETE_COPY = {
  baseLabel: (nativeName: string) => `Delete language name in ${nativeName}`,
  overrideLabel: (localeCode: string) =>
    `Remove locale correction ${localeCode}`,
  title: (target: HilosI18nLanguageNameDeleteTarget) =>
    target.kind === 'base'
      ? `Delete name · ${target.languageNativeName} in ${target.inLanguageNativeName}`
      : `Remove correction · ${target.languageNativeName} in ${target.localeCode}`,
  baseBody: 'The language, its locales and its names in other languages stay.',
  cascade: 'All locale corrections of this base name will also be deleted.',
  overrideBody:
    'Only this locale correction will be removed. The locale will inherit the current base name.',
  overrideNote:
    'An empty value is a saved correction too. Removing it restores inheritance.',
  current: 'Value being removed:',
  fallback: 'Shown instead (native name):',
  inherited: 'Base name to inherit:',
  empty: 'Empty value',
  emptyDescription: 'A saved empty string, not an absent name.',
  missing: 'The name no longer exists.',
  cancel: 'Cancel',
  delete: 'Delete',
  remove: 'Remove',
  refusalTitle: "Couldn't delete the language name",
} as const

/** Bind both deletions to the tracked dispatcher, leaving all data to the subscription. */
export function createHilosI18nLanguageNameDelete(
  context: HilosI18nLanguageContext,
): { deleteName(target: HilosI18nLanguageNameDeleteTarget): ActionHandle } {
  return {
    deleteName: (target) => {
      const payload = {
        languageId: target.languageId,
        inLanguageId: target.inLanguageId,
        nameId: target.nameId,
        expectedName: target.expectedName,
      }
      return target.kind === 'base'
        ? context.actions.dispatch(
            HILOS_I18N_LANGUAGE_NAME_DELETE_ACTION,
            payload,
          )
        : context.actions.dispatch(
            HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE_ACTION,
            {
              ...payload,
              localeId: target.localeId,
            },
          )
    },
  }
}
