// Built-in catalog counts delivered in the page scope (HIL-112).
// The numbers are fixed for a running daemon and refresh on restart.
import { z } from 'zod'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the built-in catalog tally. */
export const BUILT_IN_CATALOG_DATA = 'builtInCatalog'

/** The page-data key of the built-in country codes. */
export const BUILT_IN_COUNTRY_CODES_DATA = 'builtInCountryCodes'

/** The page-data key of the built-in languages. */
export const BUILT_IN_LANGUAGES_DATA = 'builtInLanguages'

export const builtInLanguageSchema = z.strictObject({
  code: z.string(),
  nativeName: z.string(),
  rtl: z.boolean(),
})

export type HilosI18nBuiltInLanguage = z.infer<typeof builtInLanguageSchema>

export const builtInCountryCodesSchema = z.array(z.string())

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

/**
 * Read the built-in country codes from the countries page response.
 * Refuses unexpected shapes by returning null.
 *
 * @param context The project's page scopes.
 */
export function createHilosI18nBuiltInCountryCodes(context: {
  readonly scopes: ScopeManager
}): ReadonlySignal<readonly string[] | null> {
  const source = context.scopes.pageDataSignal(BUILT_IN_COUNTRY_CODES_DATA)
  return computedSignal(() => {
    const parsed = builtInCountryCodesSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}

/**
 * Read the ordered built-in languages from the languages page response.
 * Refuses unexpected shapes by returning null.
 *
 * @param context The project's page scopes.
 */
export function createHilosI18nBuiltInLanguages(context: {
  readonly scopes: ScopeManager
}): ReadonlySignal<readonly HilosI18nBuiltInLanguage[] | null> {
  const source = context.scopes.pageDataSignal(BUILT_IN_LANGUAGES_DATA)
  return computedSignal(() => {
    const parsed = z.array(builtInLanguageSchema).safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
