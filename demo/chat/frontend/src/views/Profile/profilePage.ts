// The profile page selectors: the current user's committed display name read
// from the live self-connection data the page subscription delivers (backend
// ProfilePage binds SelfConnectionBrowserData, which carries the DB user name).
// Reading the committed name reactively — rather than the one-shot session
// current user — is what lets the edit modal detect a rename landing (success)
// and a concurrent rename from another tab (conflict). The view reads these
// signals and never touches a raw store.
import {
  computedSignal,
  hilosNotificationPreferences,
  NOTIFICATION_SIGNAL_PREFERENCES_CHANGED,
  notificationPreferencesSectionSchema,
  OAUTH_GITHUB_AUTH_METHOD,
  readString,
  subscribeSignal,
  type EntityRef,
  type HilosNotificationPreferencesChanged,
  type HilosNotificationPreferencesStore,
  type ProjectSignal,
  type ReadonlySignal,
} from '@hilos/core'

import { connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'
import { Identities, PasskeyCredentials } from '../../types'
import { type IdentityItem } from './types/lists/IdentityItem'
import { type PasswordSection } from './types/PasswordSection'
import { type ProfileDetail } from './types/ProfileDetail'

// The identity `type` values (backend `IdentityType`) the password section reasons
// about: a password identity means the Change form, and an email-bearing identity
// (password or magic_link) is a candidate proven email for the Add form.
const PASSWORD_IDENTITY_TYPE = 'password'
const MAGIC_LINK_IDENTITY_TYPE = 'magic_link'

// The single-row data slot carrying this connection's own state
// (backend ChatBrowserTable::SELF_CONNECTION), including the DB user name.
const SELF_CONNECTION_DATA = 'selfConnection'
const NAME_FIELD = 'name'
// The profile's linked-identities list and the DB-source slot carrying its
// identity references (backend ProfileIdentitiesBrowserList).
const PROFILE_IDENTITIES_LIST = 'profileIdentities'
const IDENTITIES_SLOT = 'identities'
// The second DB-source slot of the same list: the passkey credential sidecars of
// the same owner, joined back to their identity row by `identityId` (HIL-418).
const PASSKEY_CREDENTIALS_SLOT = 'passkeyCredentials'

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
 * A passkey row additionally carries the device it was enrolled on and when,
 * joined in from the same anchor's credential slot (HIL-418).
 */
export const profileIdentities: ReadonlySignal<readonly IdentityItem[]> =
  computedSignal(() => {
    const refs = profileIdentityItems.get().flatMap((item) => {
      const slot = item.slots[IDENTITIES_SLOT]

      return Array.isArray(slot) ? (slot as EntityRef[]) : []
    })
    const canUnlink = refs.length > 1
    // A passkey's readable half lives in its credential sidecar, so the two are
    // matched by the identity id the sidecar carries. Any other method resolves
    // to nothing here and keeps both fields null.
    const credentials = profileIdentityItems.get().flatMap((item) => {
      const slot = item.slots[PASSKEY_CREDENTIALS_SLOT]

      return Array.isArray(slot) ? (slot as EntityRef[]) : []
    })

    return refs.map((identityRef) => {
      const identity = Identities.signal(identityRef).get()
      const identityId = identity?.id ?? Number(identityRef.id)
      const credential = credentials
        .map((credentialRef) => PasskeyCredentials.signal(credentialRef).get())
        .find((entry) => entry?.identityId === identityId)

      return {
        key: String(identity?.id ?? identityRef.id),
        type: identity?.type ?? '',
        provider: identity?.provider ?? null,
        identifier: identity?.identifier ?? '',
        verified: identity?.verified ?? false,
        deviceName: credential?.label ?? null,
        addedAt: credential?.createdAt ?? null,
        canUnlink,
      }
    })
  })

/**
 * The password section's form mode, derived reactively from the linked identities
 * (HIL-402). `hasPassword` selects the Change form; with no password, a non-null
 * `verifiedEmail` (the first verified email-bearing identity) selects the Add form,
 * and null disables the section (confirm an email first → HIL-406). Both update
 * live as identities land, so adding a password flips the section to Change the
 * moment its new row arrives.
 */
export const passwordSection: ReadonlySignal<PasswordSection> = computedSignal(
  () => {
    const list = profileIdentities.get()
    const verified = list.find(
      (identity) =>
        identity.verified &&
        (identity.type === PASSWORD_IDENTITY_TYPE ||
          identity.type === MAGIC_LINK_IDENTITY_TYPE),
    )

    return {
      hasPassword: list.some(
        (identity) => identity.type === PASSWORD_IDENTITY_TYPE,
      ),
      verifiedEmail: verified?.identifier ?? null,
    }
  },
)

/**
 * The OAuth providers this project offers to link from the profile (HIL-401).
 * Mirrors the redirect methods the sign-in surface exposes (AuthSurface), narrowed
 * to the ones a signed-in user can attach to their account; only GitHub ships now.
 */
const LINKABLE_OAUTH_METHODS = [OAUTH_GITHUB_AUTH_METHOD]

/** A provider that can still be linked to the current account: its key and button label. */
export interface AvailableProvider {
  readonly key: string
  readonly label: string
}

/**
 * The configured OAuth providers not yet linked to the current account, resolved
 * reactively (HIL-401). A provider drops off the moment its identity appears in
 * {@link profileIdentities} (a link landing), so the Profile "Link an account"
 * buttons reflect the live identity list without a bespoke ack.
 */
export const availableProviders: ReadonlySignal<readonly AvailableProvider[]> =
  computedSignal(() => {
    const linked = new Set(
      profileIdentities
        .get()
        .map((identity) => identity.provider)
        .filter(
          (provider): provider is string =>
            provider !== null && provider !== '',
        ),
    )

    return LINKABLE_OAUTH_METHODS.filter(
      (method) => !linked.has(method.key),
    ).map((method) => ({ key: method.key, label: method.label }))
  })

// The profile page-data slot carrying the notification-preferences section
// snapshot (backend AbstractHilosProfilePage::NOTIFICATION_SECTION). Absent from
// an anonymous or session-less subscription — the section has nothing to show.
const NOTIFICATION_PREFERENCES_DATA = 'notificationPreferences'

const notificationPreferencesData = scopes.pageDataSignal(
  NOTIFICATION_PREFERENCES_DATA,
)

/**
 * Feed the framework notification-preferences store (HIL-485) from the profile
 * subscription this project owns: the section snapshot rides the page-data slot,
 * and live multi-device toggles arrive as the notification_preferences_changed
 * project signal, fanned to every one of the user's connections. The SDK section
 * view renders the store; it is fed here because the profile page — where the
 * section lives — is a project surface, not a framework-bound scope of its own.
 *
 * Applies the current snapshot at once, then keeps the store in sync until the
 * returned teardown runs. The mounting view calls that teardown on unmount, which
 * also clears the store so a section left behind never lingers with stale rows.
 *
 * @param store The preferences store to feed; defaults to the shared framework store.
 * @returns Teardown that stops both feeds and clears the store.
 */
export function bindNotificationPreferences(
  store: HilosNotificationPreferencesStore = hilosNotificationPreferences,
): () => void {
  function applySection(raw: unknown): void {
    const parsed = notificationPreferencesSectionSchema.safeParse(raw)
    if (parsed.success) {
      store.applySection(parsed.data)
    }
  }

  applySection(notificationPreferencesData.get())
  const stopSection = subscribeSignal(notificationPreferencesData, applySection)

  const stopChanged = connection.on(
    'projectSignal',
    (signal: ProjectSignal) => {
      if (signal.type !== NOTIFICATION_SIGNAL_PREFERENCES_CHANGED) {
        return
      }
      // Validated against the preferences-changed schema at the parse boundary
      // (NOTIFICATION_PREFERENCE_SIGNAL_SCHEMAS, merged by createHilosConnection).
      store.applyChangedMap(
        (signal.data as HilosNotificationPreferencesChanged).channels,
      )
    },
  )

  return () => {
    stopSection()
    stopChanged()
    store.clear()
  }
}
