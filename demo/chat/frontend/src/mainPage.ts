// The main chat page selectors: the page payload's lists resolved into the
// view models the page renders. Like the session's current-user selector, the
// view reads these through useSignal and never touches a raw store — the
// reference resolution and the presence read live here, reactively, so a user
// rename or a presence flip updates without the list re-streaming.
import {
  computedSignal,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from './session'

/** A participant row of the main page roster. */
export interface Participant {
  /** The list item key (the user id), stable for keyed rendering. */
  readonly key: string
  /** The user's display name, resolved from the referenced entity. */
  readonly name: string
  /** `online` while the user holds a live connection, otherwise `offline`. */
  readonly presence: string
}

// Wire keys of the `MainUsersBrowserList` item: the user entity slot and the
// inline RT connection slot carrying presence — backend source collection
// names (see pages.ts pageEntityTypes for the sync rule).
const MAIN_USERS_LIST = 'mainUsers'
const USER_SLOT = 'users'
const CONNECTION_SLOT = 'connections'

const mainUsers = scopes.pageListSignal(MAIN_USERS_LIST)

/** The main page roster: one participant per user list item, resolved reactively. */
export const mainParticipants: ReadonlySignal<readonly Participant[]> =
  computedSignal(() =>
    mainUsers.get().map((item) => {
      const ref = item.slots[USER_SLOT] as EntityRef | undefined
      const name = ref ? scopes.entitySignal(ref).get()?.fields.name : undefined
      const connection = item.slots[CONNECTION_SLOT] as
        | { presence?: unknown }
        | undefined
      const presence = connection?.presence

      return {
        key: item.itemKey,
        name: typeof name === 'string' ? name : '',
        presence: typeof presence === 'string' ? presence : 'offline',
      }
    }),
  )
