<template>
  <div class="card h-100">
    <div class="accordion accordion-flush" id="participantsAccordion">
      <div class="accordion-item border-0">
        <h2 class="accordion-header" id="headingUsers">
          <button
            class="accordion-button"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#collapseUsers"
            aria-expanded="true"
            aria-controls="collapseUsers"
          >
            <strong>Users ({{ chatStore.onlineUsers.length }} online)</strong>
          </button>
        </h2>
        <div
          id="collapseUsers"
          class="accordion-collapse collapse show"
          aria-labelledby="headingUsers"
        >
          <div class="accordion-body p-0">
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
      </div>
      <div class="accordion-item border-0">
        <h2 class="accordion-header" id="headingBots">
          <button
            class="accordion-button collapsed"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#collapseBots"
            aria-expanded="false"
            aria-controls="collapseBots"
          >
            <strong>Bots ({{ chatStore.bots.length }})</strong>
          </button>
        </h2>
        <div
          id="collapseBots"
          class="accordion-collapse collapse"
          aria-labelledby="headingBots"
        >
          <div class="accordion-body p-0">
            <div class="bots-scroll">
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
    return (a.id ?? 0) - (b.id ?? 0)
  })
)

const sortedBots = computed(() =>
  [...chatStore.bots].sort((a, b) => a.id - b.id)
)
</script>

<style scoped>
.users-scroll,
.bots-scroll {
  max-height: 40vh;
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

.bot-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex: 0 0 10px;
  background-color: #6f42c1;
}
</style>
