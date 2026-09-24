<!-- The "it was not me" relay of a second-factor removal (HIL-494). Every notice
about a removal carries a link to this static SPA route
(/auth/second-factor/cancel?token=…); the view relays the token as the cancel
action and says the outcome in place. No sign-in is involved and none results:
the token alone names the removal, so the page works in a browser that never
signed in — the person reading the notice may be anywhere.

The route loads cold from a click in a mail client, so the cancel waits for a
connection that can carry it and gives the wait up on the connection's own
verdict that the server cannot be reached (HIL-1044), exactly as the magic-link
relay does; Try again repeats the step whole. A token is spent only by a cancel
that worked, so a retry after a failed wait is safe.
Bootstrap classes only, no CSS of its own (styling-rules.md): the column is the
grid's, not a width of this page's. -->
<script setup lang="ts">
import {
  createAuthActions,
  whenPageReadyOrUnreachable,
  type HilosAuthContext,
} from '@hilos/core'
import { inject, onMounted, onUnmounted, ref } from 'vue'

import { hilosRouterKey } from '../hilosRouterKey.js'

defineOptions({ name: 'HilosSecondFactorCancelPage' })

const props = defineProps<{
  /** The project context the relay dispatches the cancel over. */
  context: HilosAuthContext
}>()

const authActions = createAuthActions(props.context)

const injectedRouter = inject(hilosRouterKey)
if (!injectedRouter) {
  throw new Error(
    'HilosSecondFactorCancelPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const router = injectedRouter

// `verifying` while the token is in flight, `done` once the removal is canceled,
// `error` once the link names no removal or the server cannot be reached.
const status = ref<'verifying' | 'done' | 'error'>('verifying')
const message = ref('')

// Whether the failure on screen is one a retry can do anything about: a link
// with no token will not grow one by asking again.
const retryable = ref(false)

// Where Continue goes: the home page, which signs the person in if they want to.
const HOME_PATH = '/'

// A link the server does not recognize and a link with nothing in it read the
// same to the person: the removal it named is not there to cancel.
const LINK_DEAD_MESSAGE = 'This link no longer works.'

// The sentence for a cancel that never reached the server.
const UNREACHABLE_MESSAGE = 'Could not reach the server. Please try again.'

// The token as it arrived, read once at mount and kept for Try again.
let linkToken = ''

// Which attempt is the one on screen; a newer one supersedes what is in flight.
let attempt = 0

function showError(reason: string, canRetry: boolean): void {
  status.value = 'error'
  message.value = reason
  retryable.value = canRetry
}

/**
 * Run the step whole: wait for a connection that can carry the cancel, dispatch
 * it, and show the outcome. Mount runs it, and so does Try again.
 */
async function runCancel(): Promise<void> {
  attempt += 1
  const mine = attempt
  status.value = 'verifying'
  message.value = ''
  if (!(await whenPageReadyOrUnreachable(props.context.connection))) {
    if (attempt === mine) {
      showError(UNREACHABLE_MESSAGE, true)
    }

    return
  }
  if (attempt !== mine) {
    return
  }

  const outcome = await authActions.cancelSecondFactorReset(linkToken)
  if (attempt !== mine) {
    return
  }
  if (outcome.ok) {
    status.value = 'done'

    return
  }
  showError(outcome.message ?? LINK_DEAD_MESSAGE, true)
}

onMounted(() => {
  linkToken = new URLSearchParams(window.location.search).get('token') ?? ''
  if (linkToken === '') {
    showError(LINK_DEAD_MESSAGE, false)

    return
  }

  void runCancel()
})

onUnmounted(() => {
  // Whatever is in flight belongs to a view that is going away.
  attempt += 1
})

function goHome(): void {
  router.navigate(HOME_PATH)
}
</script>

<template>
  <section
    data-id="auth-second-factor-cancel"
    class="row justify-content-center text-center"
  >
    <div class="col-sm-8 col-md-6 col-lg-4">
      <div
        v-if="status === 'verifying'"
        role="status"
        data-id="auth-second-factor-cancel-verifying"
      >
        <span class="spinner-border" role="status" aria-hidden="true"></span>
        <p class="mt-3">Canceling the request…</p>
      </div>

      <template v-else-if="status === 'done'">
        <div
          class="alert alert-success"
          role="status"
          data-id="auth-second-factor-cancel-done"
        >
          The request to remove two-step verification was canceled.
        </div>
        <button
          type="button"
          class="btn btn-primary"
          data-id="auth-second-factor-cancel-continue"
          @click="goHome"
        >
          Continue
        </button>
      </template>

      <template v-else>
        <div
          class="alert alert-danger"
          role="alert"
          data-id="auth-second-factor-cancel-error"
        >
          {{ message }}
        </div>
        <div class="d-flex justify-content-center gap-3">
          <button
            v-if="retryable"
            type="button"
            class="btn btn-link p-0"
            data-id="auth-second-factor-cancel-retry"
            @click="runCancel"
          >
            Try again
          </button>
          <button
            type="button"
            class="btn btn-link p-0"
            data-id="auth-second-factor-cancel-continue"
            @click="goHome"
          >
            Continue
          </button>
        </div>
      </template>
    </div>
  </section>
</template>
