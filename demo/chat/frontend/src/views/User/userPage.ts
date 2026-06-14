// The user detail page selectors: the requested user resolved by reference into
// the profile the page renders, plus the reactive runtime presence. The profile
// arrives as a page entity (UserPage::buildPagePayload), so a rename fans out
// here for free; presence and the session count ride the reactive `userPresence`
// data slot. The view reads this signal and never touches a raw store.
import {
  computedSignal,
  readNumber,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from '../../bootstrap/session'
import { Users, toPresence } from '../../types'
import { type UserDetail } from './types/UserDetail'

// Wire keys: the user profile entity slot (backend UserPage::ENTITY_SLOT) and
// the presence data slot (backend ChatBrowserTable::USER_PRESENCE).
const USER_ENTITY_SLOT = 'user'
const USER_PRESENCE_DATA = 'userPresence'
// The presence summary fields, mirroring the backend
// Demo\Chat\Runtime\View\DTO\UserConnectionSummary.
const PRESENCE_FIELD = 'presence'
const ONLINE_SESSION_COUNT_FIELD = 'onlineSessionCount'

/** Read a page data slot as an inline record, or undefined. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

const userRef = scopes.pageDataSignal(USER_ENTITY_SLOT)
const userPresenceData = scopes.pageDataSignal(USER_PRESENCE_DATA)

/**
 * The requested user's detail, or undefined until its profile entity lands: the
 * referenced user resolved reactively, with the runtime presence folded in so a
 * connect/disconnect flips `presence` without re-streaming the profile.
 */
export const userDetail: ReadonlySignal<UserDetail | undefined> =
  computedSignal(() => {
    const ref = userRef.get() as EntityRef | undefined
    const user = ref ? Users.signal(ref).get() : undefined
    if (!user) {
      return undefined
    }
    const presence = recordSlot(userPresenceData.get())

    return {
      name: user.name,
      lastActivity: user.lastActivity ?? '',
      presence: toPresence(presence?.[PRESENCE_FIELD]),
      onlineSessionCount: presence
        ? readNumber(presence, ONLINE_SESSION_COUNT_FIELD)
        : 0,
    }
  })
