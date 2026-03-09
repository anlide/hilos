<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">{{ bot ? bot.name : 'Bot' }}</h5>
        </div>
        <div class="card-body">
          <template v-if="!chatStore.isConnected">
            <div class="placeholder-glow">
              <span class="placeholder col-4 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-3 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-5" style="height: 0.875rem"></span>
            </div>
          </template>
          <template v-else-if="!bot">
            <p class="text-muted mb-0">Bot not found or not loaded yet</p>
          </template>
          <template v-else>
            <dl class="row mb-0">
              <template v-if="bot.description">
                <dt class="col-sm-3">Description</dt>
                <dd class="col-sm-9">{{ bot.description }}</dd>
              </template>
              <template v-if="bot.personality">
                <dt class="col-sm-3">Personality</dt>
                <dd class="col-sm-9">{{ bot.personality }}</dd>
              </template>
              <template v-if="bot.style">
                <dt class="col-sm-3">Style</dt>
                <dd class="col-sm-9">{{ bot.style }}</dd>
              </template>
              <template v-if="bot.topics">
                <dt class="col-sm-3">Topics</dt>
                <dd class="col-sm-9">{{ bot.topics }}</dd>
              </template>
              <dt class="col-sm-3">Status</dt>
              <dd class="col-sm-9">
                <span :class="bot.active ? 'text-success' : 'text-secondary'">
                  {{ bot.active ? 'Active' : 'Inactive' }}
                </span>
              </dd>
            </dl>
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

const botId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? parseInt(id, 10) : Number(id)
})

const bot = computed(() => {
  if (!Number.isFinite(botId.value) || botId.value <= 0) {
    return null
  }
  return chatStore.bots.find((b) => b.id === botId.value) ?? null
})
</script>
