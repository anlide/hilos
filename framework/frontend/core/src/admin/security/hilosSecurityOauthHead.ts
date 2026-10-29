import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of a provider's complete first-answer head. */
export const OAUTH_PROVIDER_HEAD_DATA = 'oauthProviderHead'

/** Provider identity and client-pair readiness, without credential values. */
export interface HilosOAuthProviderHead {
  readonly providerKey: string
  readonly label: string
  readonly configured: boolean
  readonly missingFields: number
}

/** The project binds its active page scopes to the provider head reader. */
export interface HilosOAuthProviderHeadContext {
  readonly scopes: ScopeManager
}

const oauthProviderHeadSchema = z
  .strictObject({
    providerKey: z.string(),
    label: z.string(),
    configured: z.boolean(),
    missingFields: z.number().int().min(0).max(2),
  })
  .refine(
    ({ configured, missingFields }) => configured === (missingFields === 0),
  )
  .nullable()

/**
 * Read the active provider page's head. Only the server's explicit null means unknown.
 * Missing or invalid data and another active page yield undefined.
 *
 * @param context The project's page scopes.
 */
export function createHilosOAuthProviderHead(
  context: HilosOAuthProviderHeadContext,
): ReadonlySignal<HilosOAuthProviderHead | null | undefined> {
  const source = context.scopes.pageDataSignal(OAUTH_PROVIDER_HEAD_DATA)

  return computedSignal(() => {
    if (context.scopes.page()?.key !== HilosPages.SECURITY_OAUTH_PROVIDER) {
      return undefined
    }
    const parsed = oauthProviderHeadSchema.safeParse(source.get())

    return parsed.success ? parsed.data : undefined
  })
}
