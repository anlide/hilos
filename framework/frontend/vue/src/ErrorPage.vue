<!-- ErrorPage — the SDK's full-page subscription-error surface. HilosView
renders it in place of the routed page component whenever the navigator carries a
page subscription error (subscription_page_error), displaying its server title and
message. The connection stays live, so "Back to home" navigates
without a refresh. -->
<script setup lang="ts">
import type { PageError } from '@hilos/core/base'

import HilosLink from './HilosLink.vue'

defineProps<{ error: PageError }>()
</script>

<template>
  <div
    class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center py-5"
    data-id="page-error"
    :data-error-code="error.httpCode"
  >
    <h1 class="display-4 fw-bold text-body-secondary">{{ error.httpCode }}</h1>
    <p class="lead mb-1">{{ error.title }}</p>
    <p class="text-body-secondary">{{ error.message }}</p>
    <HilosLink to="/" class="btn btn-primary mt-3" data-id="page-error-home">
      Back to home
    </HilosLink>
  </div>
</template>
