<!-- The channel's thematic shell supplies the shared head's permitted live
name, picture, badges and description. HilosAdminPage owns the heading and row. -->
<script setup lang="ts">
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
  type ReadonlySignal,
  type ScopeManager,
} from '@hilos/core/base'
import type { HilosChannelSummary } from '@hilos/core/admin/communications'
import { inject, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The subscribing channel page. */
  page: string
  /** The current page's scopes, shared with the navigator's identity reader. */
  scopes: ScopeManager
  /** The permitted summary from this page's answer and live updates. */
  summary: ReadonlySignal<HilosChannelSummary | null>
}>()

const router = inject(hilosRouterKey)
const currentSummary = computedSignal(() => {
  const page = props.scopes.page()?.key
  const route = router?.currentRoute.get()
  if (
    page !== props.page ||
    route?.page !== props.page ||
    (page !== HilosPages.COMMUNICATIONS_CHANNEL &&
      page !== HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION &&
      page !== HilosPages.COMMUNICATIONS_CHANNEL_FRAME &&
      page !== HilosPages.COMMUNICATIONS_DELIVERIES)
  ) {
    return null
  }

  const summary = props.summary.get()

  return summary?.channel === route.params.channelId ? summary : null
})
const unbindName = bindPageInstanceName(
  props.scopes,
  HilosPages.COMMUNICATIONS_CHANNEL,
  computedSignal(() => currentSummary.get()?.label),
)
onUnmounted(unbindName)
const channel = useSignal(currentSummary)
</script>

<template>
  <HilosAdminPage :page="page" head-kind="entity" tabs-label="Channel pages">
    <template #head-visual>
      <span
        class="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
        data-id="channel-head-icon"
      >
        <i class="bi bi-envelope-paper"></i>
      </span>
    </template>
    <template v-if="channel" #head-badges>
      <HilosHideable :value="channel.enabled">
        <template #default="{ value }">
          <span
            class="badge"
            :class="value ? 'text-bg-success' : 'text-bg-secondary'"
            data-id="channel-head-enabled"
          >
            {{ value ? 'Enabled' : 'Disabled' }}
          </span>
        </template>
      </HilosHideable>
      <span
        class="badge"
        :class="channel.configured ? 'text-bg-success' : 'text-bg-warning'"
        :title="`${channel.missingFields} field(s) not set`"
        data-id="channel-head-configured"
      >
        {{
          channel.configured ? 'Configured' : `${channel.missingFields} missing`
        }}
      </span>
    </template>
    <template v-if="channel" #head-description>
      <p class="text-body-secondary mb-0" data-id="channel-head-description">
        Channel <code>{{ channel.channel }}</code> · driver
        <code>{{ channel.driver ?? '—' }}</code>
      </p>
    </template>
    <slot />
  </HilosAdminPage>
</template>
