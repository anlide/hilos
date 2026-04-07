<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">User</h5>
        </div>
        <div class="card-body">
          <template v-if="!connectionStore.isConnected">
            <div class="placeholder-glow">
              <span class="placeholder col-4 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-3 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-5" style="height: 0.875rem"></span>
            </div>
          </template>
          <template v-else>
            <p class="text-muted">User profile</p>
            <p>User ID: {{ userId }}</p>
            <p v-if="userName">Name: {{ userName }}</p>
            <p v-else class="text-muted">Name not loaded yet</p>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useConnectionStore } from '@hilos/sdk/stores'
import { useChatStore } from '@/stores'

const route = useRoute()
const connectionStore = useConnectionStore()
const chatStore = useChatStore()

const userId = computed(() => Number(route.params.id))
const userName = computed(() => {
  if (!Number.isFinite(userId.value)) {
    return ''
  }

  const user = chatStore.users.find(item => item.id === userId.value)
  return user?.name ?? ''
})
</script>
