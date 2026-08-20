<!-- The magic-link confirm relay (HIL-283). The emailed sign-in link opens this
static SPA route (/auth/magic?email=…&token=…); the app has already opened its
single live connection (subscribed to the main page through the router fallback),
so this view relays the token as the CONFIRM_MAGIC_LINK action the request-only
auth surface never sends. A valid token upgrades the session on the backend; this
then navigates home, where the now-authenticated main page renders. A missing or
rejected token shows a generic error with a way back to sign-in — no token detail
is disclosed.

The route is entered by a click in a mail client, so it loads cold: the confirm is
held behind the framework's page-ready gate until the connection can carry it
(HIL-607, in `authActions`). Waiting is the one thing that can go on forever, so
this screen carries the backstop the gate deliberately does not — the same 20s the
OAuth callback relay uses — and offers Try again, which repeats the step whole.
The link is not spent by a wait that failed: a token is consumed only by a
successful check, so a retry is the one action that actually helps here.
Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import {
  createAuthActions,
  whenPageReady,
  type HilosAuthContext,
} from '@hilos/core'
import { inject, onMounted, ref } from 'vue'

import { hilosRouterKey } from '../hilosRouterKey.js'

defineOptions({ name: 'HilosMagicLinkPage' })

const props = defineProps<{
  /** The project context the relay dispatches the confirm over. */
  context: HilosAuthContext
}>()

// The framework wire, bound to that context: this relay dispatches the confirm the
// sign-in surface itself never sends (its magic-link entry is request-only).
const authActions = createAuthActions(props.context)

const injectedRouter = inject(hilosRouterKey)
if (!injectedRouter) {
  throw new Error(
    'HilosMagicLinkPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
// Hoist the guarded router into a non-optional local so its non-undefined type
// (not just a flow narrowing) carries into the nested `goToSignIn` closure below.
const router = injectedRouter

// The relay outcome: `verifying` while the token is in flight, `error` once it is
// rejected, malformed, or given up on. A success navigates away, so it needs no
// visible state.
const status = ref<'verifying' | 'error'>('verifying')
const message = ref('')

// Whether the failure on screen is one a retry can do anything about. A malformed
// link is not — nothing in the URL will change by asking again — so it shows the
// way back to sign-in alone.
const retryable = ref(false)

// The home path the successful sign-in lands on; also the target of the "back to
// sign-in" link, where the auth surface shows for the still-anonymous session.
const HOME_PATH = '/'

// Client-side backstop on the wait for a connection that can carry the confirm:
// the page-ready gate parks forever by design, so if no page is ever answered,
// give the wait up rather than let the spinner wedge. The same value as
// HilosOAuthCallbackPage's CALLBACK_TIMEOUT_MS — the two relays wait on the same
// thing and there is no reason for one to be more patient than the other.
const MAGIC_LINK_TIMEOUT_MS = 20000

// The generic sentence for a confirm that never reached the server, matching what
// `describeAuthError` shows for a dropped connection: here it is the honest
// report it was not, since the frame really did fail to leave.
const UNREACHABLE_MESSAGE = 'Could not reach the server. Please try again.'

// The link as it arrived, read once at mount and kept so Try again can repeat the
// step from the same URL — the query is not re-read, because nothing navigated.
let linkEmail = ''
let linkToken = ''

function showError(reason: string, canRetry: boolean): void {
  status.value = 'error'
  message.value = reason
  retryable.value = canRetry
}

/**
 * Wait for a connection that can carry the confirm, and give the wait up after
 * the backstop. Giving up has to end the ATTEMPT, not merely the spinner: a wait
 * abandoned on screen but still pending underneath would dispatch whenever the
 * connection eventually settled, spend the one-time token on a screen already
 * showing an error, and leave Try again nothing left to succeed with.
 *
 * @returns True when a page answered in time, false when the wait was given up on.
 */
function waitForConnection(): Promise<boolean> {
  return new Promise<boolean>((resolve) => {
    const timer = setTimeout(() => resolve(false), MAGIC_LINK_TIMEOUT_MS)
    void whenPageReady().then(() => {
      clearTimeout(timer)
      resolve(true)
    })
  })
}

/**
 * Run the step whole: wait for a connection that can carry the confirm, dispatch
 * it, and show the outcome. This is what mount runs and what Try again runs again
 * — a retry that skipped the wait would be the very bug this screen is here for.
 */
async function runConfirm(): Promise<void> {
  status.value = 'verifying'
  message.value = ''
  if (!(await waitForConnection())) {
    showError(UNREACHABLE_MESSAGE, true)

    return
  }

  const outcome = await authActions.confirmMagicLink(linkEmail, linkToken)
  if (outcome.ok) {
    router.navigate(HOME_PATH)

    return
  }
  showError(outcome.message ?? 'This sign-in link is invalid or expired.', true)
}

onMounted(() => {
  const params = new URLSearchParams(window.location.search)
  linkEmail = params.get('email') ?? ''
  linkToken = params.get('token') ?? ''
  if (linkEmail === '' || linkToken === '') {
    showError('This sign-in link is invalid or incomplete.', false)

    return
  }

  void runConfirm()
})

function goToSignIn(): void {
  router.navigate(HOME_PATH)
}
</script>

<template>
  <section
    data-id="auth-magic"
    class="mx-auto text-center"
    style="max-width: 24rem"
  >
    <div
      v-if="status === 'verifying'"
      role="status"
      data-id="auth-magic-verifying"
    >
      <span class="spinner-border" role="status" aria-hidden="true"></span>
      <p class="mt-3">Signing you in…</p>
    </div>

    <template v-else>
      <div class="alert alert-danger" role="alert" data-id="auth-magic-error">
        {{ message }}
      </div>
      <div class="d-flex justify-content-center gap-3">
        <button
          v-if="retryable"
          type="button"
          class="btn btn-link p-0"
          data-id="auth-magic-retry"
          @click="runConfirm"
        >
          Try again
        </button>
        <button
          type="button"
          class="btn btn-link p-0"
          data-id="auth-magic-to-login"
          @click="goToSignIn"
        >
          Back to sign in
        </button>
      </div>
    </template>
  </section>
</template>
