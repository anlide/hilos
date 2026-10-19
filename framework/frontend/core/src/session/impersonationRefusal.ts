// The server's refusal codes inside a takeover (HIL-1170). They identify the
// refusal for behavior; its sentence comes from the backend's action reason.

/**
 * The refusal codes of an action inside a takeover (PHP
 * `ActionImpersonationException::CODE_*`): only looking is allowed, or the
 * sign-in of the account is not to be touched.
 */
export const IMPERSONATION_ERROR_CODE = {
  viewOnly: 'impersonation_view_only',
  accountAccess: 'impersonated',
} as const
