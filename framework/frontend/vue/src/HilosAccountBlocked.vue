<!-- HilosAccountBlocked — the "Access closed" card of the app shell (HIL-289).
HilosLayout draws it in place of the routed content, on every url, for as long
as the session holds a blocked account: the account this browser lost, or was
refused, because the project administration blocked it. The header and footer
stay; what stood in the content — modals included — goes with it.

The card stands in a narrow centered column of Bootstrap's grid, where a sign-in
form would have stood; the difference is carried by the words and the color. The
reason is its own block, saying there is none: an empty spot would read as
"still loading". Sign out is a tracked action with the driver's defaults, busy
until the answer — the card leaves by itself with the state the answer rides
behind. Internal to the shell, not exported from the package. The words are the
core's ACCOUNT_BLOCKED_COPY, one set for the three view packages. Bootstrap
classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import {
  ACCOUNT_BLOCKED_COPY,
  dismissAccountBlocked,
  type AccountBlockedNotice,
} from '@hilos/core'

import LoadingButton from './LoadingButton.vue'
import { useTrackedAction } from './useTrackedAction.js'

defineProps<{
  /** The card the session holds: whose account it lost, when the server knows. */
  notice: AccountBlockedNotice
}>()

const { busy, run } = useTrackedAction()
const onSignOut = (): void => {
  if (busy.value) {
    return
  }
  void run(dismissAccountBlocked())
}
</script>

<template>
  <div class="row justify-content-center" data-id="account-blocked">
    <section
      class="col-12 col-sm-8 col-md-6 col-lg-4"
      aria-labelledby="hilos-account-blocked-heading"
    >
      <div class="text-center mb-3">
        <span
          class="bg-danger-subtle text-danger rounded-circle d-inline-flex align-items-center justify-content-center p-3 fs-4 lh-1"
        >
          <i class="bi bi-slash-circle" aria-hidden="true"></i>
        </span>
      </div>
      <h2
        id="hilos-account-blocked-heading"
        class="h5 text-center mb-2"
        data-id="account-blocked-heading"
      >
        {{ ACCOUNT_BLOCKED_COPY.title }}
      </h2>
      <p
        class="small text-body-secondary text-center mb-3"
        data-id="account-blocked-account"
      >
        <template v-if="notice.identifier !== null">
          {{ ACCOUNT_BLOCKED_COPY.accountLead }}
          <span class="fw-semibold">{{ notice.identifier }}</span>
          {{ ACCOUNT_BLOCKED_COPY.accountTail }}
        </template>
        <template v-else>{{ ACCOUNT_BLOCKED_COPY.accountUnnamed }}</template>
      </p>
      <div
        class="border border-danger-subtle bg-danger-subtle rounded px-3 py-2 mb-3"
        data-id="account-blocked-reason"
      >
        <div class="small fw-semibold mb-1">
          {{ ACCOUNT_BLOCKED_COPY.reasonTitle }}
        </div>
        <div class="small text-body-secondary">
          {{ ACCOUNT_BLOCKED_COPY.reasonText }}
        </div>
      </div>
      <p class="small text-body-secondary mb-3">
        {{ ACCOUNT_BLOCKED_COPY.contact }}
      </p>
      <LoadingButton
        class="btn-outline-secondary w-100"
        data-id="account-blocked-sign-out"
        :loading="busy"
        @click="onSignOut"
      >
        {{ ACCOUNT_BLOCKED_COPY.signOut }}
      </LoadingButton>
    </section>
  </div>
</template>
