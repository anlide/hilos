<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden justify-content-center">
    <div class="col-12 col-md-8 col-lg-6 d-flex flex-column h-100 min-h-0">
      <div class="d-flex flex-column justify-content-center flex-grow-1 overflow-auto py-5 text-center">
        <h1 class="display-4 fw-bold text-body-secondary">{{ code }}</h1>
        <p class="lead">{{ title }}</p>
        <p class="text-body-secondary">{{ description }}</p>
        <div>
          <router-link to="/" class="btn btn-primary mt-3">Back to Home</router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'

const route = useRoute()
const code = computed(() => String(route.meta?.errorCode ?? 404))

const errorMessages: Record<string, { title: string; description: string }> = {
  '400': { title: 'Bad Request', description: 'The request could not be understood.' },
  '401': { title: 'Unauthorized', description: 'Authentication is required.' },
  '402': { title: 'Payment Required', description: 'Payment is required to access this resource.' },
  '403': { title: 'Forbidden', description: 'Access denied for guests.' },
  '404': { title: 'Page Not Found', description: 'The page you are looking for does not exist.' },
  '405': { title: 'Method Not Allowed', description: 'The request method is not allowed.' },
  '408': { title: 'Request Timeout', description: 'The server timed out waiting for the request.' },
  '409': { title: 'Conflict', description: 'The request could not be completed due to a conflict.' },
  '410': { title: 'Gone', description: 'The resource is no longer available.' },
  '429': { title: 'Too Many Requests', description: 'Too many requests. Please try again later.' },
  '500': { title: 'Internal Server Error', description: 'An unexpected error occurred.' },
  '502': { title: 'Bad Gateway', description: 'The server received an invalid response.' },
  '503': { title: 'Service Unavailable', description: 'The service is temporarily unavailable.' },
  '504': { title: 'Gateway Timeout', description: 'The gateway timed out.' }
}

const title = computed(
  () => errorMessages[code.value]?.title ?? 'Error'
)
const description = computed(
  () => errorMessages[code.value]?.description ?? 'An error occurred.'
)

useHead({
  title: `${code.value} - ${title.value} | Chat Hilos Demo`,
  meta: [
    {
      name: 'description',
      content: description.value
    }
  ]
})
</script>
