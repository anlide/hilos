// The main country card is one page-scoped browser datum. Its summary is a
// read-only hint; the server checks any future delete action at write time.
import { z } from 'zod'
import { type ActionLifecycle } from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the main country card. */
export const COUNTRY_CARD_DATA = 'countryCard'

export const countryCardSchema = z.strictObject({
  countryId: z.number().int().positive(),
  code: z.string(),
  currencySymbol: z.string(),
  currencyCode: z.string(),
  enabled: z.boolean(),
  summary: z.strictObject({
    name: z.string().nullable(),
    isOwn: z.boolean(),
    defaultLocaleId: z.number().int().positive().nullable(),
    defaultLocaleCode: z.string().nullable(),
    defaultLocale: z.enum(['missing', 'off', 'on']),
    localeCount: z.number().int().nonnegative(),
    nameCount: z.number().int().nonnegative(),
    locales: z.array(
      z.strictObject({
        localeId: z.number().int().positive(),
        code: z.string(),
        enabled: z.boolean(),
      }),
    ),
    catalogDefaultLocaleCode: z.string().nullable(),
    canDelete: z.boolean(),
    deleteReason: z.enum(['known', 'locales', 'names']).nullable(),
  }),
})

export type HilosI18nCountryCard = z.infer<typeof countryCardSchema>

/** The permitted page name, with a display-only fallback for an unnamed country. */
export function hilosI18nCountryDisplayName(
  card: HilosI18nCountryCard,
): string {
  return card.summary.name === null || card.summary.name === ''
    ? 'Unnamed country'
    : card.summary.name
}

/** The project binds its live connection and page scopes to the shared card. */
export interface HilosI18nCountryContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
  /** The tracked action dispatcher for country-card mutations. */
  readonly actions: ActionLifecycle
}

/**
 * Read one complete card from the page response and its live updates.
 * An empty data source after deletion clears the previous card.
 *
 * @param context The project's connection and page scopes.
 */
export function createHilosI18nCountryCard(
  context: HilosI18nCountryContext,
): ReadonlySignal<HilosI18nCountryCard | null> {
  const source = context.scopes.pageDataSignal(COUNTRY_CARD_DATA)
  return computedSignal(() => {
    const parsed = countryCardSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
