import { z } from 'zod'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { hideable } from '../../state/hideableSchema.js'
import { type Hideable } from '../../state/hiddenValue.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { ANALYTICS_NO_VALUE } from './hilosAnalytics.js'

/** Page data section carrying the complete browser-session header. */
export const ANALYTICS_BROWSER_SESSION_SECTION = 'browserSession'
/** Route parameter naming the numeric analytics session, never its token. */
export const ANALYTICS_BROWSER_SESSION_ROUTE_PARAM = 'browserSessionId'

/** Row payload key of the numeric browser-session identity. */
const BROWSER_SESSION_ID_FIELD = 'id'
/** Row payload key of the short browser and operating-system description. */
const BROWSER_SESSION_BROWSER_FIELD = 'browser'
/** Row payload key of the last account to sign in during this session. */
const BROWSER_SESSION_LAST_SIGNED_IN_USER_ID_FIELD = 'lastSignedInUserId'
/** Row payload key of that account's current name or deleted-account label. */
const BROWSER_SESSION_LAST_SIGNED_IN_USER_LABEL_FIELD = 'lastSignedInUserLabel'

const browserSessionSchema = z.object({
  [BROWSER_SESSION_ID_FIELD]: z.number().int().positive(),
  [BROWSER_SESSION_BROWSER_FIELD]: z.string().nullable(),
  [BROWSER_SESSION_LAST_SIGNED_IN_USER_ID_FIELD]: z
    .number()
    .int()
    .positive()
    .nullable(),
  [BROWSER_SESSION_LAST_SIGNED_IN_USER_LABEL_FIELD]: z.string().nullable(),
})

/** Header carried by the page's first response, hidden as a whole for viewers. */
export const hilosAnalyticsBrowserSessionSchema = hideable(browserSessionSchema)

/** The received header before the admin view mode hides it. */
export type HilosAnalyticsBrowserSession = z.infer<typeof browserSessionSchema>

/** Shared words for the browser-session header in every view layer. */
export const ANALYTICS_BROWSER_SESSION_COPY = {
  lastSignedIn: 'Last signed in',
  noOneSignedIn: 'No one has signed in in this browser session',
} as const

/**
 * Read the current page header, keeping missing or malformed data distinct from a guest.
 *
 * @param scopes The page scope holding the subscription's answer.
 */
export function hilosAnalyticsBrowserSession(
  scopes: ScopeManager,
): ReadonlySignal<Hideable<HilosAnalyticsBrowserSession> | undefined> {
  const source = scopes.pageDataSignal(ANALYTICS_BROWSER_SESSION_SECTION)
  return computedSignal(() => {
    const parsed = hilosAnalyticsBrowserSessionSchema.safeParse(source.get())
    return parsed.success ? parsed.data : undefined
  })
}

/**
 * Name the last sign-in without remembering a deleted account's old name.
 *
 * @param session The visible header, narrowed by the hideable view.
 */
export function formatAnalyticsLastSignedIn(
  session: HilosAnalyticsBrowserSession,
): string {
  const { lastSignedInUserId: id, lastSignedInUserLabel: label } = session
  if (id === null) return ANALYTICS_BROWSER_SESSION_COPY.noOneSignedIn
  return label === `Deleted user #${id}`
    ? label
    : `${label ?? ANALYTICS_NO_VALUE} (#${id})`
}
