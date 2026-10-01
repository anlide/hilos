// The words of a server refusal inside a takeover (HIL-1170), one set for the
// three frontends. The server answers such a refusal with the impersonal reason
// and a code of its own, and the frontend picks the sentence by the code — the
// way the admin view mode's refusal is picked (admin/viewMode.ts). A module of
// its own with no imports, because the action lifecycle reads it through
// `actionFailureReason` and the strip's module imports the lifecycle.

/**
 * The refusal codes of an action inside a takeover (PHP
 * `ActionImpersonationException::CODE_*`): only looking is allowed, or the
 * sign-in of the account is not to be touched.
 */
export const IMPERSONATION_ERROR_CODE = {
  viewOnly: 'impersonation_view_only',
  accountAccess: 'impersonated',
} as const

/** What the screen says for each refusal code of {@link IMPERSONATION_ERROR_CODE}. */
export const IMPERSONATION_REFUSAL_COPY = {
  viewOnly: "View only: nothing can be changed in someone else's account.",
  accountAccess:
    "This is not available while you work in someone else's account",
} as const
