// The current user's own profile, read-only for now. The rename flow that edits
// it through the framework modal lands in a later step; this is just the display
// shape the view binds.
export interface ProfileDetail {
  /** The current user's display name. */
  readonly name: string
}
