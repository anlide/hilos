// Built-in catalog counts delivered in the page scope (HIL-112).
// The numbers are fixed for a running daemon and refresh on restart.
import { z } from 'zod'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the built-in catalog tally. */
export const BUILT_IN_CATALOG_DATA = 'builtInCatalog'

export const builtInCatalogSchema = z.strictObject({
  languageCount: z.number().int().nonnegative(),
  countryCount: z.number().int().nonnegative(),
})

export type HilosI18nBuiltInCatalog = z.infer<typeof builtInCatalogSchema>

/**
 * Read the built-in catalog count from the page response.
 * Refuses unexpected shapes by returning null.
 *
 * @param context The project's page scopes.
 */
export function createHilosI18nBuiltInCatalog(context: {
  readonly scopes: ScopeManager
}): ReadonlySignal<HilosI18nBuiltInCatalog | null> {
  const source = context.scopes.pageDataSignal(BUILT_IN_CATALOG_DATA)
  return computedSignal(() => {
    const parsed = builtInCatalogSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
