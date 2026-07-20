<!-- The magic-link confirm relay (HIL-283). The emailed sign-in link opens this
static SPA route (/auth/magic?email=…&token=…); the app has already opened its
single live connection (subscribed to the main page through the router fallback),
so this view relays the token as the CONFIRM_MAGIC_LINK action the request-only
auth surface never sends. A valid token upgrades the session on the backend; this
then navigates home, where the now-authenticated main page renders. A missing or
rejected token shows a generic error with a way back to sign-in — no token detail
is disclosed. Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { inject, onMounted, ref } from 'vue'
import { hilosRouterKey } from '@hilos/vue'

import { confirmMagicLink } from './authActions'

defineOptions({ name: 'MagicLink' })

const injectedRouter = inject(hilosRouterKey)
if (!injectedRouter) {
  throw new Error(
    'MagicLink requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
// Hoist the guarded router into a non-optional local so its non-undefined type
// (not just a flow narrowing) carries into the nested `goToSignIn` closure below.
const router = injectedRouter

// The relay outcome: `verifying` while the token is in flight, `error` once it is
// rejected or malformed. A success navigates away, so it needs no visible state.
const status = ref<'verifying' | 'error'>('verifying')
const message = ref('')

// The home path the successful sign-in lands on; also the target of the "back to
// sign-in" link, where the auth surface shows for the still-anonymous session.
const HOME_PATH = '/'

onMounted(async () => {
  const params = new URLSearchParams(window.location.search)
  const email = params.get('email') ?? ''
  const token = params.get('token') ?? ''
  if (email === '' || token === '') {
    status.value = 'error'
    message.value = 'This sign-in link is invalid or incomplete.'

    return
  }

  const outcome = await confirmMagicLink(email, token)
  if (outcome.ok) {
    router.navigate(HOME_PATH)

    return
  }
  status.value = 'error'
  message.value = outcome.message ?? 'This sign-in link is invalid or expired.'
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
      <span
        class="spinner-border"
        role="status"
        aria-hidden="true"
      ></span>
      <p class="mt-3">Signing you in…</p>
    </div>

    <template v-else>
      <div class="alert alert-danger" role="alert" data-id="auth-magic-error">
        {{ message }}
      </div>
      <button
        type="button"
        class="btn btn-link p-0"
        data-id="auth-magic-to-login"
        @click="goToSignIn"
      >
        Back to sign in
      </button>
    </template>
  </section>
</template>
