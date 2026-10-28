import {
  MAGIC_LINK_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  SMS_METHOD_KEY,
  type HilosProfileSignInMethod,
} from '@hilos/core/base'

// Bootstrap glyphs belong to the SDK view layer, never to the core method
// descriptors. An unlisted method still renders with the generic glyph.
const METHOD_ICONS: Record<string, string> = {
  [PASSKEY_FLOW_METHOD.key]: 'bi bi-fingerprint',
  [MAGIC_LINK_FLOW_METHOD.key]: 'bi bi-envelope',
  'oauth:github': 'bi bi-github',
  'oauth:google': 'bi bi-google',
}

/** The glyph an unlisted method key falls back to. */
const GENERIC_ICON = 'bi bi-box-arrow-in-right'

/**
 * Resolve one auth method's glyph, with a generic fallback for unknown keys.
 *
 * @param key The auth method key.
 * @returns The Bootstrap icon classes.
 */
export function hilosAuthMethodIcon(key: string): string {
  return METHOD_ICONS[key] ?? GENERIC_ICON
}

/**
 * Resolve a profile sign-in method's glyph from its type and provider.
 *
 * @param method The profile method's type and provider.
 * @returns The Bootstrap icon classes.
 */
export function hilosProfileSignInIcon(
  method: Pick<HilosProfileSignInMethod, 'type' | 'provider'>,
): string {
  if (method.type === PASSWORD_METHOD_KEY) return 'bi bi-lock'
  if (method.type === SMS_METHOD_KEY) return 'bi bi-phone'
  if (method.type === 'oauth') return hilosAuthMethodIcon(method.provider ?? '')
  return hilosAuthMethodIcon(method.type)
}
