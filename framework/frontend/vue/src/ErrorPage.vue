<!-- ErrorPage — the SDK's full-page subscription-error surface. HilosView
renders it in place of the routed page component whenever the navigator carries a
page subscription error (subscription_page_error), mapping the HTTP status to a
title and description. The connection stays live, so "Back to home" navigates
without a refresh. -->
<script setup lang="ts">
import { computed } from 'vue'
import type { PageSubscriptionError } from '@hilos/core'

import HilosLink from './HilosLink.vue'

const props = defineProps<{ error: PageSubscriptionError }>()

/** HTTP status → human-readable title and description for the error surface. */
const ERROR_TEXT: Record<number, { title: string; description: string }> = {
  400: {
    title: 'Bad Request',
    description: 'The request could not be understood.',
  },
  401: { title: 'Unauthorized', description: 'Authentication is required.' },
  403: { title: 'Forbidden', description: 'Access denied for guests.' },
  404: {
    title: 'Page Not Found',
    description: 'The page you are looking for does not exist.',
  },
  500: {
    title: 'Internal Server Error',
    description: 'An unexpected error occurred.',
  },
  502: {
    title: 'Bad Gateway',
    description: 'The server received an invalid response.',
  },
  503: {
    title: 'Service Unavailable',
    description: 'The service is temporarily unavailable.',
  },
  504: { title: 'Gateway Timeout', description: 'The gateway timed out.' },
}

const fallback = { title: 'Error', description: 'An error occurred.' }
const text = computed(() => ERROR_TEXT[props.error.httpCode] ?? fallback)
</script>

<template>
  <div
    class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center py-5"
    data-id="page-error"
    :data-error-code="error.httpCode"
  >
    <h1 class="display-4 fw-bold text-body-secondary">{{ error.httpCode }}</h1>
    <p class="lead mb-1">{{ text.title }}</p>
    <p class="text-body-secondary">{{ text.description }}</p>
    <HilosLink to="/" class="btn btn-primary mt-3" data-id="page-error-home">
      Back to home
    </HilosLink>
  </div>
</template>
