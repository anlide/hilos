// The profile page selector: the current user resolved by reference into the
// read-only profile the view renders. The current user arrives in the session
// scope under the `currentUser` slot (framework sessionScope.ts) on the
// handshake response, so the profile reads it there rather than from a page
// payload — the page subscription itself authorizes the view and (later) carries
// the rename action. The view reads this signal and never touches a raw store.
import {
  computedSignal,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from '../../bootstrap/session'
import { Users } from '../../types'
import { type ProfileDetail } from './types/ProfileDetail'

// The session-scope slot the handshake response leaves the current user under
// (framework sessionScope.ts DEFAULT_CURRENT_USER_SLOT).
const CURRENT_USER_SLOT = 'currentUser'

const currentUserRef = scopes.session.data.signal(CURRENT_USER_SLOT)

/**
 * The current user's profile, or undefined until the handshake response lands:
 * the session-scope current user resolved reactively, so a rename fans out here
 * without a refresh.
 */
export const profileDetail: ReadonlySignal<ProfileDetail | undefined> =
  computedSignal(() => {
    const ref = currentUserRef.get() as EntityRef | undefined
    const user = ref ? Users.signal(ref).get() : undefined
    if (!user) {
      return undefined
    }

    return {
      name: user.name,
    }
  })
