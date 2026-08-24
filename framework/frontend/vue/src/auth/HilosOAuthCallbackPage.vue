<!-- The OAuth callback relay (HIL-281, HIL-633). The provider (or the offline stub)
brings a browser window back to this static SPA route
(/auth/callback?code=…&state=…), and this view has exactly two jobs, depending on
which window it is.

COURIER — the usual one. The trip opened this window, so it has an opener: hand the
return over by postMessage and close. Nothing is dispatched from here, because the
daemon answers the exchange on the acceptKey of the connection that asked, and the
connection that must hear the answer is the one in the page the person never left.

COLD — no opener, which means the window that started the trip is gone (it was
closed while the person was at the provider, or somebody opened this URL directly).
Then this page is the only one left to finish in: the core runs the exchange over
this document's own connection and this view navigates on the outcome. It is the
only way to complete a link whose starting window is gone.

The outcome itself is the core's to decide either way; what is left here is where to
go and what to say when it will not complete. Bootstrap classes only, no CSS of its
own (styling-rules.md). -->
<script setup lang="ts">
import { inject, onMounted, onUnmounted, ref } from 'vue'
import {
  createOAuthLogin,
  OAUTH_RETURN_MESSAGE_TYPE,
  type HilosAuthContext,
  type OAuthReturnMessage,
} from '@hilos/core'
import { hilosRouterKey } from '../hilosRouterKey.js'

defineOptions({ name: 'HilosOAuthCallbackPage' })

const props = defineProps<{
  /** The project context the cold path dispatches over. */
  context: HilosAuthContext
}>()

// The framework OAuth client, bound to that context. The trip state it reads is the
// framework module's own, so a cold return here sees the stash the start left.
const oauth = createOAuthLogin(props.context)

const injectedRouter = inject(hilosRouterKey)
if (!injectedRouter) {
  throw new Error(
    'HilosOAuthCallbackPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
// Hoist the guarded router into a non-optional local so its non-undefined type
// carries into the closures below.
const router = injectedRouter

// The relay outcome: `verifying` while the cold exchange is in flight, `error` once
// it fails. Every other ending navigates away, so it needs no visible state.
const status = ref<'verifying' | 'error'>('verifying')
const message = ref('')

// The home path a successful sign-in lands on; also the "back to sign-in" target,
// where the auth surface shows for the still-anonymous session.
const HOME_PATH = '/'

// The profile path a successful account link (HIL-401) returns to: the link was
// started there, the session is unchanged, and the new method now shows in the list.
const PROFILE_PATH = '/profile'

let unsubscribeOutcome: (() => void) | undefined

onMounted(() => {
  const params = new URLSearchParams(window.location.search)
  const payload: OAuthReturnMessage = {
    type: OAUTH_RETURN_MESSAGE_TYPE,
    code: params.get('code') ?? '',
    state: params.get('state') ?? '',
    error: params.get('error') ?? '',
  }
  // A browser with no opener answers null; a DOM that never defines the property
  // at all answers undefined, and both mean the same thing — nobody to hand this to.
  const opener = (window.opener ?? null) as Window | null
  if (opener !== null) {
    // Targeted at our own origin, so the message cannot be read by a document
    // that merely happens to hold a handle on this window.
    opener.postMessage(payload, window.location.origin)
    window.close()

    return
  }

  // Armed before the resume, because a return with nothing to exchange is refused
  // synchronously and would otherwise be answered to nobody.
  unsubscribeOutcome = oauth.subscribeOAuthOutcome((outcome) => {
    if (outcome.kind === 'error') {
      status.value = 'error'
      message.value = outcome.message

      return
    }
    router.navigate(outcome.kind === 'linked' ? PROFILE_PATH : HOME_PATH)
  })
  oauth.resumeOAuthReturn(payload.code, payload.state, payload.error)
})

onUnmounted(() => {
  unsubscribeOutcome?.()
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
