<!-- ErrorPage — the SDK's full-page subscription-error surface. HilosView
renders it in place of the routed page component whenever the navigator carries a
page subscription error (subscription_page_error), displaying its server title and
message. The connection stays live, so "Back to home" navigates
without a refresh.

A refusal that carries the optional transitionMessage — the server's own line for
a page whose resource was deleted while it stood open (HIL-1603) — shows it under
the description, as plain text. The key's presence in the current error is the
only thing that draws it: no status, code or phrase is checked, and the next
error without the key takes the line away. An opted-in page shell draws this
surface in place of its content and returns focus to its Back to home link, which
it reads through the exposed homeLink(). -->
<script setup lang="ts">
import type { PageError } from '@hilos/core/base'
import { ref } from 'vue'

import HilosLink from './HilosLink.vue'

defineProps<{ error: PageError }>()

const home = ref<InstanceType<typeof HilosLink> | null>(null)

defineExpose({
  /** The surface's Back to home anchor, or null before it is mounted. */
  homeLink: (): HTMLElement | null =>
    (home.value?.$el as HTMLElement | undefined) ?? null,
})
</script>

<template>
  <div
    class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center py-5"
    data-id="page-error"
    :data-error-code="error.httpCode"
  >
    <h1 class="display-4 fw-bold text-body-secondary">{{ error.httpCode }}</h1>
    <p class="lead mb-1">{{ error.title }}</p>
    <p
      class="text-body-secondary"
      :class="{ 'mb-1': error.transitionMessage !== undefined }"
    >
      {{ error.message }}
    </p>
    <p
      v-if="error.transitionMessage !== undefined"
      class="small text-body-secondary"
      data-id="page-error-transition-message"
    >
      {{ error.transitionMessage }}
    </p>
    <HilosLink
      ref="home"
      to="/"
      class="btn btn-primary mt-3"
      data-id="page-error-home"
    >
      Back to home
    </HilosLink>
  </div>
</template>
