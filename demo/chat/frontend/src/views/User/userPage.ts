// The user detail page selectors: the requested user resolved by reference into
// the profile the page renders, plus the reactive runtime presence, the merge
// the account may have gone into (HIL-1292) and the person's photo (HIL-1300).
// The profile arrives as a page entity (UserPage::buildPagePayload), so a rename
// fans out here for free; presence and the session count ride the reactive
// `userPresence` data slot, the merge rides the `userMerge` slot, which is
// absent for an account never merged, and the photo rides the `personPhoto`
// slot, absent for a person without one. The view reads this signal and never
// touches a raw store.
import {
  computedSignal,
  readNumber,
  readNumberOrNull,
  readStringOrNull,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core/base'

import { scopes } from '../../bootstrap/session'
import { Users, toPresence } from '../../types'
import { type UserDetail } from './types/UserDetail'

// Wire keys: the user profile entity slot (backend UserPage::ENTITY_SLOT), the
// presence data slot (backend ChatBrowserData::USER_PRESENCE), the merge data
// slot (backend ChatBrowserData::USER_MERGE) and the photo data slot (backend
// ChatBrowserData::USER_PHOTO — `personPhoto`, as `userPhoto` is the framework's
// admin photo card and browser keys share one space).
const USER_ENTITY_SLOT = 'user'
const USER_PRESENCE_DATA = 'userPresence'
const USER_MERGE_DATA = 'userMerge'
const USER_PHOTO_DATA = 'personPhoto'
// The presence summary fields, mirroring the backend
// Hilos\Runtime\View\DTO\HilosUserPresenceSummary.
const PRESENCE_FIELD = 'presence'
const ONLINE_SESSION_COUNT_FIELD = 'onlineSessionCount'
// The merge fields, mirroring the backend HilosUserTableRow::FIELD_MERGED_INTO
// and FIELD_MERGED_INTO_NAME.
const MERGED_INTO_FIELD = 'mergedInto'
const MERGED_INTO_NAME_FIELD = 'mergedIntoName'
// The photo row's field carrying the address of its public avatar variant,
// mirroring the backend Hilos\Database\View\Item\UserPhoto::photo.
const PHOTO_FIELD = 'photo'

/** Read a page data slot as an inline record, or undefined. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

const userRef = scopes.pageDataSignal(USER_ENTITY_SLOT)
const userPresenceData = scopes.pageDataSignal(USER_PRESENCE_DATA)
const userMergeData = scopes.pageDataSignal(USER_MERGE_DATA)
const userPhotoData = scopes.pageDataSignal(USER_PHOTO_DATA)

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
    // The merge slot exists only for an account that was folded into another one.
    const merge = recordSlot(userMergeData.get())
    // The photo slot exists only for a person who has a photo.
    const photo = recordSlot(userPhotoData.get())

    return {
      name: user.name,
      lastActivity: user.lastActivity ?? '',
      presence: toPresence(presence?.[PRESENCE_FIELD]),
      onlineSessionCount: presence
        ? readNumber(presence, ONLINE_SESSION_COUNT_FIELD)
        : 0,
      merged: merge !== undefined,
      mergedInto: merge ? readNumberOrNull(merge, MERGED_INTO_FIELD) : null,
      mergedIntoName: merge
        ? readStringOrNull(merge, MERGED_INTO_NAME_FIELD)
        : null,
      photo: photo ? readStringOrNull(photo, PHOTO_FIELD) : null,
    }
  })
