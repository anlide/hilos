import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { hideable } from '../../state/hideableSchema.js'
import { isHiddenValue, type Hideable } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the complete Security overview. */
export const SECURITY_OVERVIEW_DATA = 'securityOverview'

/** Complete directory tallies and policies, preserving settings hidden from a viewer. */
export interface HilosSecurityOverview {
  readonly twoFactor: {
    readonly enabled: Hideable<boolean>
    readonly required: Hideable<'none' | 'admins' | 'everyone'>
  }
  readonly stepUp: {
    readonly enabledCount: Hideable<number>
    readonly totalCount: number
  }
  readonly oauth: {
    readonly configuredCount: number
    readonly totalCount: number
    readonly unconfigured: readonly {
      readonly providerKey: string
      readonly label: string
      readonly missingFields: number
    }[]
  }
  readonly signInMethods: {
    readonly enabledCount: Hideable<number>
    readonly totalCount: number
    readonly unready: Hideable<
      readonly { readonly methodKey: string; readonly label: string }[]
    >
  }
  readonly impersonation: {
    readonly allowed: Hideable<boolean>
    readonly scope: Hideable<'view' | 'act'>
  }
}

/** The project binds its active page scopes to the overview reader. */
export interface HilosSecurityOverviewContext {
  readonly scopes: ScopeManager
}

const enabledCountSchema = z
  .strictObject({
    enabledCount: hideable(z.number().int().nonnegative()),
    totalCount: z.number().int().nonnegative(),
  })
  .refine(
    ({ enabledCount, totalCount }) =>
      isHiddenValue(enabledCount) || enabledCount <= totalCount,
  )

const securityOverviewSchema = z.strictObject({
  twoFactor: z.strictObject({
    enabled: hideable(z.boolean()),
    required: hideable(z.enum(['none', 'admins', 'everyone'])),
  }),
  stepUp: enabledCountSchema,
  oauth: z
    .strictObject({
      configuredCount: z.number().int().nonnegative(),
      totalCount: z.number().int().nonnegative(),
      unconfigured: z.array(
        z.strictObject({
          providerKey: z.string(),
          label: z.string(),
          missingFields: z.number().int().min(1).max(2),
        }),
      ),
    })
    .refine(({ configuredCount, totalCount }) => configuredCount <= totalCount),
  signInMethods: enabledCountSchema.safeExtend({
    unready: hideable(
      z.array(z.strictObject({ methodKey: z.string(), label: z.string() })),
    ),
  }),
  impersonation: z.strictObject({
    allowed: hideable(z.boolean()),
    scope: hideable(z.enum(['view', 'act'])),
  }),
})

/**
 * Read the active Security page's overview without retaining missing or invalid data.
 *
 * @param context The project's page scopes.
 */
export function createHilosSecurityOverview(
  context: HilosSecurityOverviewContext,
): ReadonlySignal<HilosSecurityOverview | undefined> {
  const source = context.scopes.pageDataSignal(SECURITY_OVERVIEW_DATA)

  return computedSignal(() => {
    if (context.scopes.page()?.key !== HilosPages.SECURITY) return undefined
    const parsed = securityOverviewSchema.safeParse(source.get())

    return parsed.success ? parsed.data : undefined
  })
}
