// The framework profile list (HIL-1277) supplies the current account's ways in.
// Both framework profile pages resolve it from their page scope; projects bind
// the list on the backend and do not pass sign-in methods through view props.
import {
  readBoolean,
  readNumber,
  readString,
  readStringOrNull,
} from '../state/fieldReaders.js'
import { type EntityRef } from '../state/EntityStore.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../state/signal.js'
import {
  resolveHilosProfileSignInMethods,
  type HilosProfileSignInIdentitySource,
  type HilosProfileSignInMethod,
  type HilosProfileSignInPasskeySource,
} from './profileSignInMethods.js'

/** Browser list key declared by HilosProfileIdentitiesBrowserList::LIST. */
export const HILOS_PROFILE_IDENTITIES_LIST = 'profileIdentities'

const IDENTITIES_SLOT = 'identities'
const PASSKEY_CREDENTIALS_SLOT = 'passkeyCredentials'

const ProfileIdentityRowKey = {
  id: 'id',
  type: 'type',
  provider: 'provider',
  identifier: 'identifier',
  verified: 'verified',
} as const

const ProfilePasskeyRowKey = {
  identityId: 'identityId',
  label: 'label',
  createdAt: 'createdAt',
} as const

function references(slot: unknown): readonly EntityRef[] {
  return Array.isArray(slot) ? (slot as EntityRef[]) : []
}

/**
 * Resolve the page's normalized identity and passkey sidecar references live.
 *
 * @param scopes The scope manager containing the current profile page list.
 */
export function createHilosProfileSignInMethods(
  scopes: ScopeManager,
): ReadonlySignal<readonly HilosProfileSignInMethod[]> {
  const items = scopes.pageListSignal(HILOS_PROFILE_IDENTITIES_LIST)

  return computedSignal(() => {
    const list = items.get()
    const identities: HilosProfileSignInIdentitySource[] = list.flatMap(
      (item) =>
        references(item.slots[IDENTITIES_SLOT]).flatMap((ref) => {
          const fields = scopes.entitySignal(ref).get()?.fields
          return fields === undefined
            ? []
            : [
                {
                  id: readNumber(fields, ProfileIdentityRowKey.id),
                  type: readString(fields, ProfileIdentityRowKey.type),
                  provider: readStringOrNull(
                    fields,
                    ProfileIdentityRowKey.provider,
                  ),
                  identifier: readString(
                    fields,
                    ProfileIdentityRowKey.identifier,
                  ),
                  verified: readBoolean(fields, ProfileIdentityRowKey.verified),
                },
              ]
        }),
    )
    const passkeys: HilosProfileSignInPasskeySource[] = list.flatMap((item) =>
      references(item.slots[PASSKEY_CREDENTIALS_SLOT]).flatMap((ref) => {
        const fields = scopes.entitySignal(ref).get()?.fields
        return fields === undefined
          ? []
          : [
              {
                identityId: readNumber(fields, ProfilePasskeyRowKey.identityId),
                label: readStringOrNull(fields, ProfilePasskeyRowKey.label),
                createdAt: readString(fields, ProfilePasskeyRowKey.createdAt),
              },
            ]
      }),
    )

    return resolveHilosProfileSignInMethods(identities, passkeys)
  })
}
