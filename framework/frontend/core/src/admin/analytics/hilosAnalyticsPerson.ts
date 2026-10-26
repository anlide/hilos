// The Person page of Analytics: its route parameter identifies the target, and
// its name and photo come from that page subscription's own scope.

import { z } from 'zod'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { hideable } from '../../state/hideableSchema.js'
import { type Hideable } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { HilosPages } from '../../routing/hilosPages.js'

/** Page-data key carrying the person's current name. */
const PERSON_NAME_FIELD = 'name'
/** Page-data key carrying the person's current photo. */
const PERSON_PHOTO_FIELD = 'photo'

const hilosAnalyticsPersonSchema = z.object({
  [PERSON_NAME_FIELD]: hideable(z.string().nullable()),
  [PERSON_PHOTO_FIELD]: hideable(z.string().nullable()),
})

/** The current Person identity carried by its page subscription. */
export type HilosAnalyticsPerson = {
  readonly name: Hideable<string | null>
  readonly photo: Hideable<string | null>
}

/** Route parameter naming the person by their numeric account id. */
export const ANALYTICS_PERSON_ROUTE_PARAM = 'userId'

/**
 * Read the current Person identity from the matching page scope.
 *
 * @param scopes The scopes holding the active page subscription.
 */
export function hilosAnalyticsPerson(
  scopes: ScopeManager,
): ReadonlySignal<HilosAnalyticsPerson | undefined> {
  const name = scopes.pageDataSignal(PERSON_NAME_FIELD)
  const photo = scopes.pageDataSignal(PERSON_PHOTO_FIELD)

  return computedSignal(() => {
    if (scopes.page()?.key !== HilosPages.ANALYTICS_PERSON) return undefined

    const parsed = hilosAnalyticsPersonSchema.safeParse({
      [PERSON_NAME_FIELD]: name.get(),
      [PERSON_PHOTO_FIELD]: photo.get(),
    })
    return parsed.success ? parsed.data : undefined
  })
}
