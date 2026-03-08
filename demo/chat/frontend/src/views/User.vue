<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">User</h5>
        </div>
        <div class="card-body">
          <template v-if="!chatStore.isConnected">
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
import { useChatStore } from '@/stores'

const route = useRoute()
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
