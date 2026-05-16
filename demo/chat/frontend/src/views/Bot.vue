<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">{{ bot ? bot.name : 'Bot' }}</h5>
        </div>
        <div class="card-body">
          <template v-if="!connectionStore.isConnected">
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
              <dt class="col-sm-3">Config</dt>
              <dd class="col-sm-9">
                <span :class="bot.active ? 'text-success' : 'text-secondary'">
                  {{ bot.active ? 'Active' : 'Inactive' }}
                </span>
              </dd>
              <dt class="col-sm-3">Runtime</dt>
              <dd class="col-sm-9">
                <span :class="bot.presence === 'online' ? 'text-success' : 'text-secondary'">
                  {{ bot.presence }}
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
import { useBrowserStore, useConnectionStore } from '@hilos/sdk/stores'
import {
  BROWSER_PAGE_BOT,
  BROWSER_TABLE_BOT_DETAIL,
  botDetailFromBrowserRows,
} from '@/entities/browserBotDetail'

const route = useRoute()
const browserStore = useBrowserStore()
const connectionStore = useConnectionStore()

const botId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? parseInt(id, 10) : Number(id)
})

const bot = computed(() => {
  if (!Number.isFinite(botId.value) || botId.value <= 0) {
    return null
  }
  return botDetailFromBrowserRows(
    browserStore.pages[BROWSER_PAGE_BOT]?.tables[BROWSER_TABLE_BOT_DETAIL]?.rowsByKey,
    botId.value,
  )
})
</script>
