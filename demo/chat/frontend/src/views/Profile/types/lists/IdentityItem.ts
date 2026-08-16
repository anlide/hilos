// A linked login identity of the profile's `profileIdentities` list — the
// view-model of one list item: the referenced identity's non-secret fields the
// "login methods" section renders. A view-model, not an entity (its `key` is the
// list item key); the profile selector builds it from the resolved entity.

/** One linked login-identity row of the profile. */
export interface IdentityItem {
  /** The list item key (the identity id), stable for keyed rendering. */
  readonly key: string
  /** Identity kind (password, magic_link, sms, oauth, passkey). */
  readonly type: string
  /** OAuth provider label, or null for non-oauth methods. */
  readonly provider: string | null
  /** The public identifier (email, phone, oauth subject). */
  readonly identifier: string
  /** Whether this identity has been verified. */
  readonly verified: boolean
  /**
   * The device a passkey was enrolled on ("Chrome on macOS"), null for every
   * other method and for a passkey whose browser was not recognized (HIL-418).
   */
  readonly deviceName: string | null
  /** When a passkey was registered, as an SQL datetime; null for every other method. */
  readonly addedAt: string | null
  /**
   * Whether this identity may be unlinked — false for the user's only login
   * method (a UX hint; the server re-enforces the last-identity guard).
   */
  readonly canUnlink: boolean
}
