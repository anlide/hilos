<template>
  <div class="card h-100">
    <div class="card-header">
      <strong>Users</strong>
    </div>
    <div class="card-body p-0">
      <div class="users-scroll">
        <div v-if="sortedUsers.length === 0" class="text-muted p-3">
          No users yet
        </div>
        <div
          v-for="user in sortedUsers"
          :key="user.id ?? `user-${user.name}`"
          class="d-flex align-items-center gap-2 px-3 py-2 border-bottom"
        >
          <span
            class="presence-dot"
            :class="user.presence === 'online' ? 'presence-online' : 'presence-offline'"
          />
          <RouterLink
            v-if="user.id !== null"
            class="text-decoration-none text-body"
            :to="{ name: 'user', params: { id: user.id } }"
          >
            {{ user.name }}
          </RouterLink>
          <span v-else>{{ user.name }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useChatStore } from '@/stores'

const chatStore = useChatStore()

const sortedUsers = computed(() =>
  [...chatStore.users].sort((a, b) => {
    if (a.presence !== b.presence) {
      return a.presence === 'online' ? -1 : 1
    }
    return a.name.localeCompare(b.name)
  })
)
</script>

<style scoped>
.users-scroll {
  max-height: 60vh;
  overflow-y: auto;
}

.presence-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: 0 0 10px;
}

.presence-online {
  background-color: #198754;
}

.presence-offline {
  background-color: #dc3545;
}
</style>
