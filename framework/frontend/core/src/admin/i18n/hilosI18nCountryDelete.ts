import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { type HilosI18nCountryRow } from './hilosI18nCountries.js'
import {
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from './hilosI18nCountry.js'

/** Tracked delete action owned by the country card. */
export const HILOS_I18N_COUNTRY_DELETE_ACTION = 'hilos_i18n_country_delete'

/** Tracked delete action owned by the countries list. */
export const HILOS_I18N_COUNTRY_ROW_DELETE_ACTION =
  'hilos_i18n_country_row_delete'

/** A dialog's frozen target and the same projection of its live source. */
export interface HilosI18nCountryDeleteTarget {
  readonly countryId: number
  readonly code: string
  readonly name: string | null
  readonly canDelete: boolean
  readonly deleteReason: 'known' | 'locales' | 'names' | null
}

/** Words shared by both deletion doors; reasons come from the server's hint. */
export const HILOS_I18N_COUNTRY_DELETE_COPY = {
  open: 'Delete country',
  title: (label: string) => `Delete country · ${label}`,
  body: 'This custom country has no locales or names referring to it. It will be removed entirely.',
  note: 'A country from the built-in catalog cannot be deleted: the reflow would bring it back. Switch it off instead.',
  cancel: 'Cancel',
  confirm: 'Delete',
  refusalTitle: "Couldn't delete the country",
  elsewhere: 'Deleted elsewhere.',
  allowed: 'Can be deleted: nothing refers to it yet',
  unavailable: 'Cannot delete: country details are unavailable',
  reasons: {
    known:
      'Cannot delete: the built-in catalog knows this country and the reflow would bring it back; switch it off instead',
    locales: 'Cannot delete: locales refer to this country',
    names: 'Cannot delete: names refer to this country',
  },
} as const

/** Choose the name for a deletion control, or the uppercase code without a name. */
export function hilosI18nCountryDeleteLabel(
  target: HilosI18nCountryDeleteTarget,
): string {
  return target.name || target.code.toUpperCase()
}

/** Render the server's reason, with a closed fallback for unavailable data. */
export function hilosI18nCountryDeleteReason(
  target: HilosI18nCountryDeleteTarget | null,
): string {
  if (target?.deleteReason != null) {
    return HILOS_I18N_COUNTRY_DELETE_COPY.reasons[target.deleteReason]
  }
  return target?.canDelete === true
    ? HILOS_I18N_COUNTRY_DELETE_COPY.allowed
    : HILOS_I18N_COUNTRY_DELETE_COPY.unavailable
}

/**
 * Read why a table row's deletion is blocked, in the server's own sentence,
 * never looked up in the copy by code; null when deletion is allowed. The card
 * and the dialog target keep {@link hilosI18nCountryDeleteReason}.
 *
 * @param row The live row.
 */
export function hilosI18nCountryRowDeleteReason(
  row: Pick<HilosI18nCountryRow, 'deleteDisabledReason'>,
): string | null {
  return row.deleteDisabledReason
}

/** Project a parsed row without deriving a second deletion policy. */
export function hilosI18nCountryDeleteFromRow(
  row: HilosI18nCountryRow | null | undefined,
): HilosI18nCountryDeleteTarget | null {
  if (row == null) return null
  return {
    countryId: row.countryId,
    code: row.code,
    name: row.name,
    canDelete: row.canDelete,
    deleteReason: row.deleteReason,
  }
}

/** Project the existing card verdict without changing its wire contract. */
export function hilosI18nCountryDeleteFromCard(
  card: HilosI18nCountryCard | null,
): HilosI18nCountryDeleteTarget | null {
  if (card === null) return null
  return {
    countryId: card.countryId,
    code: card.code,
    name: card.summary.name,
    canDelete: card.summary.canDelete,
    deleteReason: card.summary.deleteReason,
  }
}

/** Bind the card's deletion door to the project's tracked action lifecycle. */
export function createHilosI18nCountryDelete(
  context: HilosI18nCountryContext,
): { deleteCountry(countryId: number): ActionHandle } {
  return {
    deleteCountry: (countryId) =>
      context.actions.dispatch(HILOS_I18N_COUNTRY_DELETE_ACTION, {
        countryId,
      }),
  }
}

/** Bind the list's deletion door to the project's tracked action lifecycle. */
export function createHilosI18nCountryRowDelete(
  context: HilosI18nCountryContext,
): { deleteCountry(countryId: number): ActionHandle } {
  return {
    deleteCountry: (countryId) =>
      context.actions.dispatch(HILOS_I18N_COUNTRY_ROW_DELETE_ACTION, {
        countryId,
      }),
  }
}
