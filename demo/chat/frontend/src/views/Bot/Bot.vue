<!-- The bot detail page (PAGE_BOT): a read-only profile of one chat bot reached
at /bot/:id. The profile resolves from the page entity the subscription
delivered, so an admin rename re-renders here without a refresh; the agent's
online state rides the reactive botStatus slot, flipping the badge on join or
leave. Shows an entity head with small HilosAvatar, name, presence and active
badges, description, and the fields list below without a card wrapper.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosAvatar, useSignal } from '@hilos/vue/base'

import { botDetail } from './botPage.js'

defineOptions({ name: 'BotPage' })

const detail = useSignal(botDetail)
</script>

<template>
  <section data-id="bot-view">
    <div v-if="detail" data-id="bot-detail">
      <div class="d-flex align-items-start gap-2 mb-4" data-id="bot-head">
        <HilosAvatar :name="detail.name" size="sm" />
        <div class="flex-grow-1 overflow-hidden">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <h1 class="h4 mb-0 text-break mw-100" data-id="bot-name">
              {{ detail.name }}
            </h1>
            <span
              class="badge"
              :class="detail.online ? 'text-bg-success' : 'text-bg-secondary'"
              data-id="bot-presence"
              >{{ detail.online ? 'online' : 'offline' }}</span
            >
            <span
              class="badge"
              :class="
                detail.active
                  ? 'text-bg-primary'
                  : 'bg-body-tertiary text-body-emphasis'
              "
              data-id="bot-active"
              >{{ detail.active ? 'active' : 'inactive' }}</span
            >
          </div>
          <p
            v-if="detail.description"
            class="text-body-secondary mb-0 mt-1 text-break"
            data-id="bot-description"
          >
            {{ detail.description }}
          </p>
        </div>
      </div>
      <dl
        v-if="detail.personality || detail.style || detail.topics"
        class="row mb-0"
        data-id="bot-fields"
      >
        <template v-if="detail.personality">
          <dt class="col-sm-3">Personality</dt>
          <dd class="col-sm-9 text-break" data-id="bot-personality">
            {{ detail.personality }}
          </dd>
        </template>
        <template v-if="detail.style">
          <dt class="col-sm-3">Style</dt>
          <dd class="col-sm-9 text-break" data-id="bot-style">
            {{ detail.style }}
          </dd>
        </template>
        <template v-if="detail.topics">
          <dt class="col-sm-3">Topics</dt>
          <dd class="col-sm-9 text-break" data-id="bot-topics">
            {{ detail.topics }}
          </dd>
        </template>
      </dl>
    </div>
    <p v-else class="text-body-secondary" data-id="bot-empty">Loading bot…</p>
  </section>
</template>
