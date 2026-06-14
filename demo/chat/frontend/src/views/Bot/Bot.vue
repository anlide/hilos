<!-- The bot detail page (PAGE_BOT): a read-only profile of one chat bot reached
at /bot/:id. The profile resolves from the page entity the subscription
delivered, so an admin rename re-renders here without a refresh; the agent's
online state rides the reactive botStatus slot, flipping the badge on join or
leave. A skeleton shows until the profile entity lands (or when the id is
unknown and the subscription is rejected). Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { useSignal } from '@hilos/vue'

import { botDetail } from './botPage'

defineOptions({ name: 'BotPage' })

const detail = useSignal(botDetail)
</script>

<template>
  <section data-id="bot-view">
    <div v-if="detail" class="card" data-id="bot-detail">
      <div class="card-header d-flex align-items-center gap-2 flex-wrap">
        <span class="h5 mb-0" data-id="bot-name">
          <span class="me-1" aria-hidden="true">🤖</span>{{ detail.name }}
        </span>
        <span
          class="badge"
          :class="detail.online ? 'text-bg-success' : 'text-bg-secondary'"
          data-id="bot-presence"
          >{{ detail.online ? 'online' : 'offline' }}</span
        >
        <span
          class="badge"
          :class="detail.active ? 'text-bg-primary' : 'text-bg-light'"
          data-id="bot-active"
          >{{ detail.active ? 'active' : 'inactive' }}</span
        >
      </div>
      <div class="card-body">
        <dl class="row mb-0">
          <template v-if="detail.description">
            <dt class="col-sm-3">Description</dt>
            <dd class="col-sm-9" data-id="bot-description">
              {{ detail.description }}
            </dd>
          </template>
          <template v-if="detail.personality">
            <dt class="col-sm-3">Personality</dt>
            <dd class="col-sm-9" data-id="bot-personality">
              {{ detail.personality }}
            </dd>
          </template>
          <template v-if="detail.style">
            <dt class="col-sm-3">Style</dt>
            <dd class="col-sm-9" data-id="bot-style">{{ detail.style }}</dd>
          </template>
          <template v-if="detail.topics">
            <dt class="col-sm-3">Topics</dt>
            <dd class="col-sm-9" data-id="bot-topics">{{ detail.topics }}</dd>
          </template>
        </dl>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="bot-empty">Loading bot…</p>
  </section>
</template>
