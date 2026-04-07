<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">SIL history — user #{{ userId }}</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary small mb-3">
            Stub timeline of SIL interactions for this user. Full audit trail will attach to the same user id as the
            main product accounts.
          </p>
          <ul class="mb-0">
            <li v-for="(line, i) in lines" :key="i" class="mb-2">{{ line }}</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const userId = computed(() => (typeof route.params.userId === 'string' ? route.params.userId : ''))

const lines = computed(() => [
  `[stub] 2025-03-19 User #${userId.value} opened SIL session (policy=elevated)`,
  `[stub] 2025-03-19 Tool batch: filesystem.read_file (3), web_fetch (1)`,
  `[stub] 2025-03-18 User #${userId.value} — request sil-req-0991 completed`,
])

useHead({
  title: 'SIL user history | Hilos | Chat Demo',
})
</script>
