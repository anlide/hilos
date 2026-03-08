<template>
  <div class="card h-100 d-flex flex-column">
    <div class="card-header d-flex justify-content-between align-items-center" data-id="participants-header">
      <strong>Participants</strong>
      <span
        v-if="chatStore.isConnected"
        class="badge text-bg-secondary"
      >
        {{ sortedUsers.length + sortedBots.length }}
      </span>
      <span v-else class="placeholder rounded-pill" style="width: 2rem; height: 1.25rem"></span>
    </div>
    <div class="card-body p-0 overflow-auto flex-grow-1 min-h-0">
      <template v-if="!chatStore.isConnected">
        <div class="px-3 py-2 border-bottom placeholder-glow">
          <span class="placeholder col-6" style="height: 0.875rem"></span>
        </div>
        <div
          v-for="i in 4"
          :key="`user-placeholder-${i}`"
          class="d-flex align-items-center gap-2 px-3 py-2 border-bottom placeholder-glow"
        >
          <span class="placeholder rounded-circle flex-shrink-0" style="width: 10px; height: 10px"></span>
          <span class="placeholder col-7" style="height: 0.875rem"></span>
        </div>
        <div class="px-3 py-2 border-bottom placeholder-glow">
          <span class="placeholder col-5" style="height: 0.875rem"></span>
        </div>
        <div
          v-for="i in 3"
          :key="`bot-placeholder-${i}`"
          class="d-flex align-items-center gap-2 px-3 py-2 border-bottom placeholder-glow"
        >
          <span class="placeholder rounded-circle flex-shrink-0" style="width: 10px; height: 10px"></span>
          <span class="placeholder col-6" style="height: 0.875rem"></span>
        </div>
      </template>
      <template v-else>
        <div class="px-3 py-2 text-muted small border-bottom" data-id="online-users-label">
          Users ({{ chatStore.onlineUsers.length }} online)
        </div>
        <div v-if="sortedUsers.length === 0" class="text-muted p-3 border-bottom" data-id="online-users-empty">
          No users yet
        </div>
        <div
          v-for="user in sortedUsers"
          :key="user.id ?? `user-${user.name}`"
          class="d-flex align-items-center gap-2 px-3 py-2 border-bottom"
          data-id="online-user"
        >
          <span
            class="rounded-circle flex-shrink-0"
            :class="user.presence === 'online' ? 'bg-success' : 'bg-danger'"
            style="width: 10px; height: 10px"
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
          <span class="rounded-circle flex-shrink-0 bg-secondary" style="width: 10px; height: 10px" />
          <RouterLink
            class="text-decoration-none text-body"
            :to="{ name: 'bot', params: { id: bot.id } }"
          >
            {{ bot.name }}
          </RouterLink>
        </div>
      </template>
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
