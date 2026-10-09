// The main language card is one page-scoped browser datum. Its summary is a
// read-only hint; the server checks any future delete action at write time.
import { z } from 'zod'
import { type ActionLifecycle } from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the main language card. */
export const LANGUAGE_CARD_DATA = 'languageCard'

export const languageCardSchema = z.strictObject({
  code: z.string(),
  nativeName: z.string(),
  rtl: z.boolean(),
  enabled: z.boolean(),
  summary: z.strictObject({
    isDefault: z.boolean(),
    isOwn: z.boolean(),
    localeCount: z.number().int().nonnegative(),
    nameCount: z.number().int().nonnegative(),
    canDelete: z.boolean(),
    deleteReason: z.enum(['default', 'locales', 'names']).nullable(),
  }),
})

export type HilosI18nLanguageCard = z.infer<typeof languageCardSchema>

/** The project binds its live connection and page scopes to the shared card. */
export interface HilosI18nLanguageContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
  /** The tracked action dispatcher for language-card mutations. */
  readonly actions: ActionLifecycle
}

/**
 * Read one complete card from the page response and its live updates.
 * An empty data source after deletion clears the previous card.
 *
 * @param context The project's connection and page scopes.
 */
export function createHilosI18nLanguageCard(
  context: HilosI18nLanguageContext,
): ReadonlySignal<HilosI18nLanguageCard | null> {
  const source = context.scopes.pageDataSignal(LANGUAGE_CARD_DATA)
  return computedSignal(() => {
    const parsed = languageCardSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
