<template>
  <DaemonSectionShell v-if="channel" :title="`Communications — ${channel.label}`">
    <p class="text-body-secondary small mb-3">
      Channel configuration (stub). Provider, credentials, and rate limits would live here.
    </p>
    <dl class="row mb-0">
      <dt class="col-sm-3">Channel</dt>
      <dd class="col-sm-9"><code>{{ channel.channelId }}</code> — {{ channel.label }}</dd>
      <dt class="col-sm-3">Provider</dt>
      <dd class="col-sm-9">{{ channel.providerName }}</dd>
      <dt class="col-sm-3">Status</dt>
      <dd class="col-sm-9">{{ channel.status }}</dd>
      <dt class="col-sm-3">Queue depth</dt>
      <dd class="col-sm-9">{{ channel.queueDepth }}</dd>
      <dt class="col-sm-3">Templates</dt>
      <dd class="col-sm-9">{{ channel.templatesCount }}</dd>
    </dl>
    <div class="mt-3 d-flex flex-wrap gap-2">
      <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/communications">
        All channels
      </router-link>
      <router-link
        class="btn btn-sm btn-outline-primary"
        :to="`/hilos/communications/${channel.channelId}/deliveries`"
      >
        Deliveries
      </router-link>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import {
  hilosCommunicationsChannelsStub,
  type HilosCommunicationsChannelId,
} from '@/constants/hilosCommunicationsStubs'

const route = useRoute()
const router = useRouter()

const validIds = new Set(
  hilosCommunicationsChannelsStub.map((c) => c.channelId) as HilosCommunicationsChannelId[],
)

const channelId = computed(() => {
  const raw = route.params.channelId
  return typeof raw === 'string' ? raw : ''
})

const channel = computed(() =>
  hilosCommunicationsChannelsStub.find((c) => c.channelId === channelId.value),
)

watch(
  channelId,
  (id) => {
    if (!id || !validIds.has(id as HilosCommunicationsChannelId)) {
      void router.replace('/hilos/communications')
    }
  },
  { immediate: true },
)

useHead(() => ({
  title: channel.value
    ? `${channel.value.label} | Communications | Hilos | Chat Demo`
    : 'Communications | Hilos | Chat Demo',
}))
</script>
