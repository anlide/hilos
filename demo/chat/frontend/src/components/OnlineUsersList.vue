<template>
  <div class="card h-100 d-flex flex-column">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Participants</strong>
      <span class="badge text-bg-secondary">{{ sortedUsers.length + sortedBots.length }}</span>
    </div>
    <div class="card-body p-0 overflow-auto">
      <div class="px-3 py-2 text-muted small border-bottom">
        Users ({{ chatStore.onlineUsers.length }} online)
      </div>
      <div v-if="sortedUsers.length === 0" class="text-muted p-3 border-bottom">
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

      <div class="px-3 py-2 text-muted small border-bottom">
        Bots ({{ chatStore.bots.length }})
      </div>
      <div v-if="sortedBots.length === 0" class="text-muted p-3">
        No bots yet
      </div>
      <div
        v-for="bot in sortedBots"
        :key="bot.id"
        class="d-flex align-items-center gap-2 px-3 py-2 border-bottom"
      >
        <span class="bot-dot" />
        <RouterLink
          class="text-decoration-none text-body"
          :to="{ name: 'bot', params: { id: bot.id } }"
        >
          {{ bot.name }}
        </RouterLink>
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
    return (a.id ?? 0) - (b.id ?? 0)
  })
)

const sortedBots = computed(() =>
  [...chatStore.bots].sort((a, b) => a.id - b.id)
)
</script>

<style scoped>
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

.bot-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: 0 0 10px;
  background-color: #6f42c1;
}
</style>
