// @vitest-environment happy-dom
// Covers the copy a cancelled passkey ceremony shows (HIL-725). The browser
// reports a dialog the person closed and a dialog that had nothing to offer as the
// same DOMException, so the message can only be told apart by WHICH ceremony was
// running — and the defect this file exists against was that the driver knew and
// did not say. The tests therefore drive the ceremonies through their public
// entry points rather than calling the error translator: what has to hold is that
// each ceremony hands the translator ITS OWN ceremony, and a direct call would
// pass with the bug in place. The environment is a DOM one because the ceremony IS
// browser code (`navigator.credentials`, `PublicKeyCredential`); the authenticator
// itself is stubbed, so what is tested is the driver's reaction to a rejection,
// not an emulated security key.
//
// The expected strings are written out as literals on purpose: importing the
// module's constants would make any rewording pass green, and rewording is the one
// thing this file is here to catch.
import { afterEach, describe, expect, it } from 'vitest'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import {
  AUTH_ACTION_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS,
  AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
} from '../../src/auth/authProtocol.js'
import { PASSKEY_FLOW_METHOD } from '../../src/auth/authFlow.js'
import {
  createHilosAuthContext,
  type HilosAuthContext,
} from '../../src/auth/authContext.js'
import {
  createPasskeyCeremony,
  runPasskeyDiscoverableLogin,
} from '../../src/auth/passkeyCeremony.js'
import {
  PASSKEY_CEREMONY_LOGIN,
  PASSKEY_CEREMONY_REGISTER,
  PASSKEY_OPTIONS_SIGNAL,
  type PasskeyCeremony,
} from '../../src/auth/passkeySignals.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'

/** The base64url every binary option field in the fixture carries. */
const CHALLENGE = 'AAAA'

/** Which ceremony an options action's reply belongs to. */
const CEREMONY_BY_OPTIONS_ACTION: Record<string, PasskeyCeremony | undefined> =
  {
    [AUTH_ACTION_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS]: PASSKEY_CEREMONY_LOGIN,
    [AUTH_ACTION_PASSKEY_REGISTER_OPTIONS]: PASSKEY_CEREMONY_REGISTER,
  }

/**
 * The smallest `publicKey` options each ceremony's decoder accepts: the driver
 * decodes them before it ever reaches the authenticator, so an options payload
 * too thin to decode would fail these tests for the wrong reason.
 */
const PUBLIC_KEY_OPTIONS: Record<PasskeyCeremony, Record<string, unknown>> = {
  [PASSKEY_CEREMONY_LOGIN]: { challenge: CHALLENGE, allowCredentials: [] },
  [PASSKEY_CEREMONY_REGISTER]: {
    challenge: CHALLENGE,
    rp: { name: 'Hilos' },
    user: { id: CHALLENGE, name: 'a@b.test', displayName: 'A' },
    pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
  },
}

/** The globals the driver's support gate reads, as this file may set them. */
interface PasskeyGlobals {
  PublicKeyCredential?: unknown
}

/** The navigator slot the driver calls the authenticator through. */
interface CredentialsHost {
  credentials?: unknown
}

/**
 * Stand an authenticator in that always turns the ceremony down the way a browser
 * does — by rejecting the `navigator.credentials` call with a DOMException.
 *
 * Both globals are needed, not one: `isPasskeySupported` gates on the credentials
 * container AND on `PublicKeyCredential`, and a ceremony that fails that gate
 * never reaches the copy under test.
 *
 * @param failure The DOMException both authenticator calls reject with.
 */
function installAuthenticator(failure: DOMException): void {
  const globals = globalThis as PasskeyGlobals
  globals.PublicKeyCredential = class {}
  Object.defineProperty(navigator, 'credentials', {
    configurable: true,
    value: {
      create: () => Promise.reject(failure),
      get: () => Promise.reject(failure),
    },
  })
}

/**
 * Stand up one ceremony world: a connection that fans the options signal out, an
 * action lifecycle that answers an options dispatch with that signal, and an
 * authenticator that refuses.
 *
 * The options reply is emitted from inside the dispatch itself, which is early
 * but not too early: the driver subscribes BEFORE it dispatches, precisely so a
 * signal that overtakes the ack cannot be missed.
 *
 * @param failure The DOMException the stubbed authenticator rejects with.
 * @returns The auth context the ceremonies run against.
 */
function passkeyWorld(failure: DOMException): HilosAuthContext {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      const typed = listener as unknown as (signal: ProjectSignal) => void
      if (event !== 'projectSignal') {
        return () => undefined
      }
      listeners.push(typed)

      return () => {
        const at = listeners.indexOf(typed)
        if (at >= 0) {
          listeners.splice(at, 1)
        }
      }
    },
  } as unknown as HilosConnection

  const actions = {
    dispatch: (action: string) => {
      const ceremony = CEREMONY_BY_OPTIONS_ACTION[action]
      if (ceremony !== undefined) {
        const signal = {
          kind: 'project',
          type: PASSKEY_OPTIONS_SIGNAL,
          data: {
            acceptKey: 'accept-1',
            ceremony,
            publicKeyOptions: PUBLIC_KEY_OPTIONS[ceremony],
            signedChallenge: 'signed',
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of [...listeners]) {
          listener(signal)
        }
      }

      return {
        requestId: 'req-1',
        loading: createSignal(false),
        done: Promise.resolve({}),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  installAuthenticator(failure)

  return createHilosAuthContext({
    connection,
    scopes: new ScopeManager(),
    actions,
    methods: [PASSKEY_FLOW_METHOD],
    channels: [],
    oauthProviders: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
  })
}

afterEach(() => {
  delete (globalThis as PasskeyGlobals).PublicKeyCredential
  delete (navigator as unknown as CredentialsHost).credentials
})

describe('the copy a refused passkey ceremony shows', () => {
  it('tells a cancelled sign-in how else to sign in', async () => {
    const context = passkeyWorld(new DOMException('', 'NotAllowedError'))

    const outcome = await runPasskeyDiscoverableLogin(context)

    expect(outcome).toEqual({
      ok: false,
      message:
        'The passkey request was cancelled, or this device had no matching way to sign in — try a security key or your phone.',
    })
  })

  it('says the same to a sign-in the browser aborted', async () => {
    const context = passkeyWorld(new DOMException('', 'AbortError'))

    const outcome = await runPasskeyDiscoverableLogin(context)

    expect(outcome).toEqual({
      ok: false,
      message:
        'The passkey request was cancelled, or this device had no matching way to sign in — try a security key or your phone.',
    })
  })

  it('tells a cancelled registration how else to ADD a key, not how to sign in', async () => {
    // The whole point of the leaf: this person is already signed in, standing on
    // the profile's "Add a passkey" button, and used to be advised to sign in.
    const context = passkeyWorld(new DOMException('', 'NotAllowedError'))

    const outcome = await createPasskeyCeremony(context).runPasskeyRegister()

    expect(outcome).toEqual({
      ok: false,
      message:
        'The passkey request was cancelled, or this device had no way to add one — try a security key or your phone.',
    })
  })

  it('says the same to a registration the browser aborted', async () => {
    const context = passkeyWorld(new DOMException('', 'AbortError'))

    const outcome = await createPasskeyCeremony(context).runPasskeyRegister()

    expect(outcome).toEqual({
      ok: false,
      message:
        'The passkey request was cancelled, or this device had no way to add one — try a security key or your phone.',
    })
  })

  it('leaves the already-registered message alone on the register ceremony', async () => {
    // The control: the ceremony now travels with the failure, so this guards
    // against it leaking into a branch that never asked for it.
    const context = passkeyWorld(new DOMException('', 'InvalidStateError'))

    const outcome = await createPasskeyCeremony(context).runPasskeyRegister()

    expect(outcome).toEqual({
      ok: false,
      message: 'This device already has a passkey for this account.',
    })
  })
})
