<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">User</h5>
        </div>
        <div class="card-body">
          <p class="text-muted">User profile</p>
          <p>User ID: {{ userId }}</p>
          <p v-if="userName">Name: {{ userName }}</p>
          <p v-else class="text-muted">Name not loaded yet</p>
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
