<!-- HilosCookiesRefused — the card a browser that refuses cookies sees in place
of sign-in (HIL-1074). Every sign-in rotates the session token through a cookie,
so no method can work in such a browser, and a live form would only let the
person sign in for one second. The card says why and what to do; it has no
buttons of its own — the frame it stands in closes as it always does.
Internal to the two auth screens that draw it (HilosAuthSurface and
HilosMagicLinkPage), not exported from the package. The words are the core's
COOKIES_REFUSED_COPY, one set for the three view packages.
Bootstrap classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { COOKIES_REFUSED_COPY } from '@hilos/core'

withDefaults(
  defineProps<{
    /**
     * The id the heading carries, so the frame around the card is named by it;
     * none — no id at all.
     */
    headingId?: string
    /** Head the card with the line that the sign-in link was not spent. */
    linkKept?: boolean
  }>(),
  {
    headingId: undefined,
    linkKept: false,
  },
)
</script>

<template>
  <div class="text-center" data-id="cookies-refused">
    <p v-if="linkKept" class="small mb-3" data-id="cookies-refused-link-kept">
      {{ COOKIES_REFUSED_COPY.linkKept }}
    </p>
    <span
      class="bg-warning-subtle text-warning-emphasis rounded-circle d-inline-flex align-items-center justify-content-center p-3 fs-3 lh-1 mb-3"
    >
      <i class="bi bi-cookie" aria-hidden="true"></i>
    </span>
    <h2 :id="headingId" class="h5 mb-2" data-id="cookies-refused-heading">
      {{ COOKIES_REFUSED_COPY.title }}
    </h2>
    <p class="small text-body-secondary mb-2">
      {{ COOKIES_REFUSED_COPY.message }}
    </p>
    <p class="small text-body-secondary mb-0">
      {{ COOKIES_REFUSED_COPY.remedy }}
    </p>
  </div>
</template>
