import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { type HilosI18nLanguageContext } from './hilosI18nLanguage.js'
import { type HilosI18nLanguageRow } from './hilosI18nLanguages.js'

/** Tracked delete actions of the language card and the languages table. */
export const HILOS_I18N_LANGUAGE_DELETE_ACTION = 'hilos_i18n_language_delete'
export const HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION =
  'hilos_i18n_language_row_delete'

/** The shared words of the delete controls and confirmation window. */
export const HILOS_I18N_LANGUAGE_DELETE_COPY = {
  open: 'Delete language',
  rowLabel: (nativeName: string) => `Delete language ${nativeName}`,
  title: (nativeName: string) => `Delete language · ${nativeName}`,
  body: "This language has no locales or names written by a person. Deleting it also removes the built-in catalog's country names written in it.",
  note: 'Once a locale or a manual name appears, deletion closes. You can switch the language off instead.',
  reasons: {
    default: 'Cannot delete: this is the default language.',
    locales: 'Cannot delete: the language has locales.',
    names: 'Cannot delete: names written by a person reference the language.',
  },
  cancel: 'Cancel',
  confirm: 'Delete',
  refusalTitle: "Couldn't delete the language",
  elsewhere: 'Deleted elsewhere.',
} as const

/**
 * Read the server's deletion hint, shared by a card summary and a table row.
 * The server checks the dependencies again when the action runs.
 *
 * @param source The live verdict, or null when the language has disappeared.
 */
export function hilosI18nLanguageDelete(
  source: Pick<HilosI18nLanguageRow, 'canDelete' | 'deleteReason'> | null,
): { disabled: boolean; reason: string | null } {
  return {
    disabled: source?.canDelete !== true,
    reason:
      source?.deleteReason == null
        ? null
        : HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[source.deleteReason],
  }
}

/**
 * Bind deletion to the page's tracked dispatcher without changing local data.
 *
 * @param context The project's action lifecycle.
 * @param action The delete action of the page opening the window.
 */
export function createHilosI18nLanguageDelete(
  context: HilosI18nLanguageContext,
  action:
    | typeof HILOS_I18N_LANGUAGE_DELETE_ACTION
    | typeof HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
): { deleteLanguage(languageCode: string): ActionHandle } {
  return {
    deleteLanguage: (languageCode) =>
      context.actions.dispatch(action, { languageCode }),
  }
}
