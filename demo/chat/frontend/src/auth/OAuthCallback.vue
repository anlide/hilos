<!-- The OAuth callback relay (HIL-281). The provider (or the offline stub) bounces
the browser back to this static SPA route (/auth/callback?code=…&state=…); the app
has already opened its single live connection (subscribed to the main page through
the router fallback), so this view hands the code + signed state back as the
`oauth_callback` action. The provider key rode session storage across the redirect,
since the callback URL carries only code + state.

Unlike magic-link, the login completes asynchronously: the action ack means only
"accepted, working", so the spinner stays and the outcome arrives out of band —
success as the current-user handshake fan-out (HIL-161), failure as the OAuth
result signal — with a client timeout as the last-resort backstop. A synchronous
CSRF/state rejection, a failure signal, a timeout, or a malformed return each show
a generic error with a way back to sign-in; no provider detail is disclosed.
Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { inject, onMounted, ref, watch } from 'vue'
import { hilosRouterKey, useSignal } from '@hilos/vue'

import { currentUserId } from '../bootstrap/session'
import {
  armOAuthLink,
  describeOAuthError,
  dispatchOAuthCallback,
  subscribeOAuthFailure,
  takeOAuthProvider,
} from './oauthLogin'
import {
  OAUTH_REASON_LINK_DUPLICATE,
  OAUTH_REASON_LINK_FAILED,
  OAUTH_REASON_LINK_OK,
  OAUTH_REASON_REAUTH_REQUIRED,
} from './oauthSignals'

defineOptions({ name: 'OAuthCallback' })

const injectedRouter = inject(hilosRouterKey)
if (!injectedRouter) {
  throw new Error(
    'OAuthCallback requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
// Hoist the guarded router into a non-optional local so its non-undefined type
// carries into the closures below.
const router = injectedRouter

// The relay outcome: `verifying` while the login is in flight, `error` once it is
// rejected, fails, or times out. A success navigates away, so it needs no visible
// state.
const status = ref<'verifying' | 'error'>('verifying')
const message = ref('')

// The home path a successful sign-in lands on; also the "back to sign-in" target,
// where the auth surface shows for the still-anonymous session.
const HOME_PATH = '/'

// The profile path a successful account link (HIL-401) returns to: the link was
// started there, the session is unchanged, and the new method now shows in the list.
const PROFILE_PATH = '/profile'

// The messages shown when a profile link (HIL-401) does not succeed. A duplicate is
// a distinct, non-sensitive outcome (the provider is tied to another account); any
// other link failure is generic (no provider/network detail on the wire).
const LINK_DUPLICATE_MESSAGE =
  'That account is already linked to another user.'
const LINK_FAILED_MESSAGE = 'Could not link the account. Please try again.'

// Client-side backstop past the backend exchange deadline (EXCHANGE_TTL_MS = 15s):
// if neither the current-user update nor the failure signal arrives, resolve the
// spinner to a generic error so it can never wedge.
const CALLBACK_TIMEOUT_MS = 20000

// The live current user: its turn to non-null is the success carrier (the async
// agent upgraded this session and the handshake fan-out landed).
const userId = useSignal(currentUserId)

// One-shot resolution: the first of success / failure / timeout wins and tears
// down the others, so a late signal cannot fire into a resolved view.
let settled = false
let unsubscribeFailure: (() => void) | undefined
let stopUserWatch: (() => void) | undefined
let timeoutTimer: ReturnType<typeof setTimeout> | undefined

function cleanup(): void {
  unsubscribeFailure?.()
  stopUserWatch?.()
  if (timeoutTimer !== undefined) {
    clearTimeout(timeoutTimer)
    timeoutTimer = undefined
  }
}

function fail(reason: string): void {
  if (settled) {
    return
  }
  settled = true
  cleanup()
  status.value = 'error'
  message.value = reason
}

function succeed(): void {
  if (settled) {
    return
  }
  settled = true
  cleanup()
  router.navigate(HOME_PATH)
}

// A profile account link (HIL-401) resolved successfully: the session never
// changed, so success arrives as an explicit result signal (not a current-user
// update) and the user is sent back to their profile where the new method shows.
function linkDone(): void {
  if (settled) {
    return
  }
  settled = true
  cleanup()
  router.navigate(PROFILE_PATH)
}

// The provider email collided with an existing verified account (HIL-282): not a
// failure — arm the pending link (module state, so it outlives this view and the
// sign-in surface the gate later closes) and send the user home, where the auth
// gate shows the sign-in surface pre-filled to re-authenticate. The token is
// redeemed by the global replay watcher once that re-auth upgrades the session.
function reauthToLink(email: string, linkToken: string): void {
  if (settled) {
    return
  }
  settled = true
  cleanup()
  armOAuthLink(email, linkToken)
  router.navigate(HOME_PATH)
}

onMounted(async () => {
  const params = new URLSearchParams(window.location.search)
  const code = params.get('code') ?? ''
  const state = params.get('state') ?? ''
  const provider = takeOAuthProvider()
  if (provider === '' || code === '' || state === '') {
    fail('This sign-in link is invalid or incomplete.')

    return
  }

  // Arm the out-of-band resolvers before dispatching, so an early current-user
  // update or failure signal cannot slip past an unsubscribed view.
  stopUserWatch = watch(userId, (id) => {
    if (id !== null) {
      succeed()
    }
  })
  unsubscribeFailure = subscribeOAuthFailure((data) => {
    if (
      data.reason === OAUTH_REASON_REAUTH_REQUIRED &&
      data.email !== null &&
      data.linkToken !== null
    ) {
      reauthToLink(data.email, data.linkToken)

      return
    }
    if (data.reason === OAUTH_REASON_LINK_OK) {
      linkDone()

      return
    }
    if (data.reason === OAUTH_REASON_LINK_DUPLICATE) {
      fail(LINK_DUPLICATE_MESSAGE)

      return
    }
    if (data.reason === OAUTH_REASON_LINK_FAILED) {
      fail(LINK_FAILED_MESSAGE)

      return
    }
    fail('OAuth login failed. Please try again.')
  })
  timeoutTimer = setTimeout(() => {
    fail('OAuth login timed out. Please try again.')
  }, CALLBACK_TIMEOUT_MS)

  try {
    await dispatchOAuthCallback(provider, code, state)
    // Accepted, working: the outcome arrives through the armed resolvers above.
  } catch (error) {
    fail(describeOAuthError(error))
  }
})

function goToSignIn(): void {
  router.navigate(HOME_PATH)
}
</script>

<template>
  <section
    data-id="auth-oauth-callback"
    class="mx-auto text-center"
    style="max-width: 24rem"
  >
    <div
      v-if="status === 'verifying'"
      role="status"
      data-id="auth-oauth-verifying"
    >
      <span class="spinner-border" role="status" aria-hidden="true"></span>
      <p class="mt-3">Signing you in…</p>
    </div>

    <template v-else>
      <div
        class="alert alert-danger"
        role="alert"
        data-id="auth-oauth-callback-error"
      >
        {{ message }}
      </div>
      <button
        type="button"
        class="btn btn-link p-0"
        data-id="auth-oauth-to-login"
        @click="goToSignIn"
      >
        Back to sign in
      </button>
    </template>
  </section>
</template>
