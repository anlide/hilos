import { z } from 'zod'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the internationalization overview. */
export const I18N_OVERVIEW_DATA = 'i18nOverview'

const languagesOverviewSchema = z
  .strictObject({
    enabledCount: z.number().int().nonnegative(),
    totalCount: z.number().int().nonnegative(),
    defaultLanguageCode: z.string(),
    defaultLanguageName: z.string(),
  })
  .refine(({ enabledCount, totalCount }) => enabledCount <= totalCount)

const countriesOverviewSchema = z
  .strictObject({
    enabledCount: z.number().int().nonnegative(),
    totalCount: z.number().int().nonnegative(),
    builtInCount: z.number().int().nonnegative(),
    ownCount: z.number().int().nonnegative(),
  })
  .refine(
    ({ enabledCount, totalCount, builtInCount, ownCount }) =>
      enabledCount <= totalCount && builtInCount + ownCount === totalCount,
  )

/** The complete, nonpersonal internationalization overview page data. */
export const i18nOverviewSchema = z.strictObject({
  languages: languagesOverviewSchema,
  countries: countriesOverviewSchema,
})

export type HilosI18nOverview = z.infer<typeof i18nOverviewSchema>

/** The project binds its current page scopes to the overview selector. */
export interface HilosI18nOverviewContext {
  readonly scopes: ScopeManager
}

/**
 * Read the complete overview from the current page response.
 * Missing or malformed data clears the selector instead of retaining old counts.
 *
 * @param context The project's page scopes.
 */
export function createHilosI18nOverview(
  context: HilosI18nOverviewContext,
): ReadonlySignal<HilosI18nOverview | null> {
  const source = context.scopes.pageDataSignal(I18N_OVERVIEW_DATA)
  return computedSignal(() => {
    const parsed = i18nOverviewSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
