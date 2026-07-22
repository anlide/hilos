// The profile page selectors: the current user's committed display name read
// from the live self-connection data the page subscription delivers (backend
// ProfilePage binds SelfConnectionBrowserData, which carries the DB user name).
// Reading the committed name reactively — rather than the one-shot session
// current user — is what lets the edit modal detect a rename landing (success)
// and a concurrent rename from another tab (conflict). The view reads these
// signals and never touches a raw store.
import {
  computedSignal,
  readString,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from '../../bootstrap/session'
import { Identities } from '../../types'
import { type IdentityItem } from './types/lists/IdentityItem'
import { type ProfileDetail } from './types/ProfileDetail'

// The single-row data slot carrying this connection's own state
// (backend ChatBrowserTable::SELF_CONNECTION), including the DB user name.
const SELF_CONNECTION_DATA = 'selfConnection'
const NAME_FIELD = 'name'
// The profile's linked-identities list and the DB-source slot carrying its
// identity references (backend ProfileIdentitiesBrowserList).
const PROFILE_IDENTITIES_LIST = 'profileIdentities'
const IDENTITIES_SLOT = 'identities'

/** Read a page data slot as an inline record, or undefined. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

const selfConnectionData = scopes.pageDataSignal(SELF_CONNECTION_DATA)

/**
 * The current user's committed display name from the live self-connection data,
 * or '' until the first payload lands. The edit modal diffs its draft against
 * this for the 3-way merge and watches it to detect a rename landing.
 */
export const committedName: ReadonlySignal<string> = computedSignal(() => {
  const fields = recordSlot(selfConnectionData.get())

  return fields ? readString(fields, NAME_FIELD) : ''
})

/** The read-only profile the view renders, or undefined until the name lands. */
export const profileDetail: ReadonlySignal<ProfileDetail | undefined> =
  computedSignal(() => {
    const name = committedName.get()

    return name === '' ? undefined : { name }
  })

const profileIdentityItems = scopes.pageListSignal(PROFILE_IDENTITIES_LIST)

/**
 * The current user's linked login identities, resolved reactively by reference.
 * The scoped list carries a single anchor item (the self-connection) whose
 * `identities` slot references the owner's identities; a new identity (register
 * or oauth-link) appears live, an unlink removes its row, a verified flip lands
 * on the next subscription. `canUnlink` is false for the sole remaining identity
 * so the view can disable its unlink control (the server re-enforces the guard).
 */
export const profileIdentities: ReadonlySignal<readonly IdentityItem[]> =
  computedSignal(() => {
    const refs = profileIdentityItems.get().flatMap((item) => {
      const slot = item.slots[IDENTITIES_SLOT]

      return Array.isArray(slot) ? (slot as EntityRef[]) : []
    })
    const canUnlink = refs.length > 1

    return refs.map((identityRef) => {
      const identity = Identities.signal(identityRef).get()

      return {
        key: String(identity?.id ?? identityRef.id),
        type: identity?.type ?? '',
        provider: identity?.provider ?? null,
        identifier: identity?.identifier ?? '',
        verified: identity?.verified ?? false,
        canUnlink,
      }
    })
  })
