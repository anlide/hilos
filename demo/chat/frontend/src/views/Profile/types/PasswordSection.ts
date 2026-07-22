// The password section's view-model (HIL-402), derived from the profile's linked
// identities. It drives the section's form mode: a Change form when the user
// already has a password, an Add form when they have none but a proven email to
// attach one to, and a disabled hint otherwise (confirm an email first → HIL-406).
export interface PasswordSection {
  /** Whether the user already has a `password` identity (→ Change form). */
  readonly hasPassword: boolean
  /**
   * A verified email the user can attach a password to, or null when they have
   * none (SMS-only / legacy OAuth). Non-null with no password enables the Add
   * form; null with no password disables the section.
   */
  readonly verifiedEmail: string | null
}
