// Projected sign-in methods shared by the profile root and its sign-in section.
// The framework resolves its browser-list references and joins the public fields.
import { oauthProviderOptionsFor } from '../auth/authContext.js'
import {
  PASSKEY_METHOD_KEY,
  PASSWORD_METHOD_KEY,
  SMS_METHOD_KEY,
} from '../auth/authFlow.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { type AuthMethodEntry } from '../session/sessionScope.js'
import {
  SIGNAL_PROFILE_PASSWORD_UPDATED,
  type HilosProfilePasswordUpdated,
} from './signInMethods.js'

/** The public half of an identity supplied by the framework's normalized store. */
export interface HilosProfileSignInIdentitySource {
  readonly id: number
  readonly type: string
  readonly provider: string | null
  readonly identifier: string
  readonly verified: boolean
}

/** Public device information joined to a passkey identity. */
export interface HilosProfileSignInPasskeySource {
  readonly identityId: number
  readonly label: string | null
  readonly createdAt: string
}

/** One way into the current account, with its removal hint. */
export interface HilosProfileSignInMethod {
  readonly key: string
  readonly type: string
  readonly provider: string | null
  readonly identifier: string
  readonly verified: boolean
  readonly deviceName: string | null
  readonly addedAt: string | null
  readonly canUnlink: boolean
}

/**
 * Join identities to their passkey sidecars without exposing any secret fields.
 *
 * @param identities The current account's public identities.
 * @param passkeys The same account's credential sidecars.
 */
export function resolveHilosProfileSignInMethods(
  identities: readonly HilosProfileSignInIdentitySource[],
  passkeys: readonly HilosProfileSignInPasskeySource[],
): HilosProfileSignInMethod[] {
  return identities.map((identity) => {
    const credential =
      identity.type === 'passkey'
        ? passkeys.find((entry) => entry.identityId === identity.id)
        : undefined
    return {
      key: String(identity.id),
      type: identity.type,
      provider: identity.provider,
      identifier: identity.identifier,
      verified: identity.verified,
      deviceName: credential?.label ?? null,
      addedAt: credential?.createdAt ?? null,
      canUnlink: identities.length > 1,
    }
  })
}

/**
 * Read whether a password exists and which confirmed address can receive one.
 *
 * @param methods The current account's projected methods.
 */
export function hilosProfilePasswordState(
  methods: readonly HilosProfileSignInMethod[],
): {
  hasPassword: boolean
  verifiedEmail: string | null
} {
  return {
    hasPassword: methods.some((method) => method.type === 'password'),
    verifiedEmail:
      methods.find(
        (method) =>
          method.verified &&
          (method.type === 'password' || method.type === 'magic_link'),
      )?.identifier ?? null,
  }
}

/**
 * Providers offered by the installation that are not attached to this account.
 *
 * @param methods The current account's projected methods.
 * @param offered The session's offered sign-in methods, in button order.
 */
export function hilosProfileLinkableProviders(
  methods: readonly HilosProfileSignInMethod[],
  offered: readonly AuthMethodEntry[],
): { key: string; label: string; name: string }[] {
  const linked = new Set(methods.map((method) => method.provider))
  return oauthProviderOptionsFor(offered)
    .filter((provider) => !linked.has(provider.key))
    .map(({ key, label, name }) => ({ key, label, name }))
}

/**
 * Whether device keys are the account's only way in (HIL-1166): the list is
 * not empty and every method in it is a passkey. An empty list — not arrived
 * yet, or the account has no ways in — answers false: no warning is better
 * than a warning on a guess.
 *
 * @param methods The current account's projected methods.
 */
export function isHilosProfilePasskeyOnly(
  methods: readonly HilosProfileSignInMethod[],
): boolean {
  return (
    methods.length > 0 &&
    methods.every((method) => method.type === PASSKEY_METHOD_KEY)
  )
}

/** One way the current account can still add, in the order of the add window. */
export type HilosProfileAddableWay =
  | { readonly kind: 'password' }
  | { readonly kind: 'phone' }
  | {
      readonly kind: 'provider'
      readonly key: string
      readonly label: string
      readonly name: string
    }
  | { readonly kind: 'passkey' }

/**
 * The ways the account can add right now, in the order of the add window
 * (HIL-1166). One list feeds both the passkey-only warning and the window's
 * choices, so a method the administrator switched off leaves both at once.
 *
 * @param methods The current account's projected methods.
 * @param offered The session's offered sign-in methods — on and ready.
 * @param passkeySupported Whether this browser can create a passkey.
 */
export function hilosProfileAddableWays(
  methods: readonly HilosProfileSignInMethod[],
  offered: readonly AuthMethodEntry[],
  passkeySupported: boolean,
): HilosProfileAddableWay[] {
  const isOffered = (key: string): boolean =>
    offered.some((entry) => entry.key === key)
  const ways: HilosProfileAddableWay[] = []
  if (
    !hilosProfilePasswordState(methods).hasPassword &&
    isOffered(PASSWORD_METHOD_KEY)
  ) {
    ways.push({ kind: 'password' })
  }
  if (isOffered(SMS_METHOD_KEY)) ways.push({ kind: 'phone' })
  for (const provider of hilosProfileLinkableProviders(methods, offered)) {
    ways.push({ kind: 'provider', ...provider })
  }
  if (passkeySupported && isOffered(PASSKEY_METHOD_KEY)) {
    ways.push({ kind: 'passkey' })
  }

  return ways
}

/**
 * The readable method name; a passkey is named for its device.
 *
 * @param method The projected method.
 * @param offered The session's provider names.
 */
export function hilosProfileSignInTitle(
  method: HilosProfileSignInMethod,
  offered: readonly AuthMethodEntry[],
): string {
  switch (method.type) {
    case 'password':
      return 'Password'
    case 'magic_link':
      return 'Email link'
    case 'sms':
      return 'Phone'
    case 'passkey':
      return method.deviceName ?? 'Passkey'
    case 'oauth':
      return (
        offered.find((entry) => entry.key === method.provider)?.name ??
        method.provider ??
        'Provider'
      )
    default:
      return method.type
  }
}

/**
 * The public identifier, or a passkey's device-registration date as stored.
 *
 * @param method The projected method.
 */
export function hilosProfileSignInSubtitle(
  method: HilosProfileSignInMethod,
): string {
  if (method.type !== 'passkey') return method.identifier
  return method.addedAt === null
    ? 'Passkey'
    : `Passkey · added ${method.addedAt.slice(0, 10)}`
}

/**
 * Compact live summary for the profile root; device keys are counted together.
 *
 * @param methods The current account's projected methods.
 * @param offered The session's provider names.
 */
export function describeHilosProfileSignInMethods(
  methods: readonly HilosProfileSignInMethod[],
  offered: readonly AuthMethodEntry[],
): string {
  const names = [
    ...new Set(
      methods
        .filter((method) => method.type !== 'passkey')
        .map((method) =>
          method.type === 'sms'
            ? 'phone'
            : hilosProfileSignInTitle(method, offered),
        ),
    ),
  ]
  const passkeys = methods.filter((method) => method.type === 'passkey').length
  if (passkeys > 0)
    names.push(`${passkeys} ${passkeys === 1 ? 'passkey' : 'passkeys'}`)
  if (names.length === 0) return 'No ways to sign in'
  const text = names.join(', ')
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/**
 * Follow the password's authoritative outcome, including changes in another tab.
 *
 * @param connection The connection carrying validated project signals.
 * @param handler Called with the added/changed mode.
 */
export function watchHilosProfilePasswordUpdated(
  connection: HilosConnection,
  handler: (data: HilosProfilePasswordUpdated) => void,
): () => void {
  return connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_PROFILE_PASSWORD_UPDATED) {
      handler(signal.data as HilosProfilePasswordUpdated)
    }
  })
}
