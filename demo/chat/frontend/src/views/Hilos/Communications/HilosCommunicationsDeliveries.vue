<template>
  <DaemonSectionShell v-if="channel && channelRow" :title="`Deliveries — ${channelRow.label}`">
    <p class="text-body-secondary small mb-3">
      Recent delivery attempts for this channel (stub).
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">ID</th>
            <th scope="col">When</th>
            <th scope="col">Recipient</th>
            <th scope="col">Template</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in deliveries" :key="d.id">
            <td><code>{{ d.id }}</code></td>
            <td class="small">{{ d.at }}</td>
            <td>{{ d.recipient }}</td>
            <td><code>{{ d.template }}</code></td>
            <td>
              <span
                class="badge rounded-pill"
                :class="{
                  'text-bg-success': d.status === 'sent',
                  'text-bg-warning': d.status === 'queued',
                  'text-bg-danger': d.status === 'failed',
                }"
              >
                {{ d.status }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="deliveries.length === 0" class="text-body-secondary small mb-0 mt-2">No deliveries (stub).</p>
    </div>
    <div class="mt-3 d-flex flex-wrap gap-2">
      <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/communications">All channels</router-link>
      <router-link
        class="btn btn-sm btn-outline-primary"
        :to="`/hilos/communications/${channel}`"
      >
        Channel config
      </router-link>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import DaemonSectionShell from '@/views/Hilos/Daemon/DaemonSectionShell.vue'
import {
  hilosCommunicationsChannelsStub,
  hilosCommunicationsDeliveriesByChannelStub,
  type HilosCommunicationsChannelId,
} from '@/constants/hilosCommunicationsStubs'

const route = useRoute()
const router = useRouter()

const validIds = new Set(
  hilosCommunicationsChannelsStub.map((c) => c.channelId) as HilosCommunicationsChannelId[],
)

const channel = computed(() => {
  const raw = route.params.channelId
  return typeof raw === 'string' ? raw : ''
})

const channelRow = computed(() =>
  hilosCommunicationsChannelsStub.find((c) => c.channelId === channel.value),
)

const deliveries = computed(() => {
  const id = channel.value as HilosCommunicationsChannelId
  if (!validIds.has(id)) return []
  return hilosCommunicationsDeliveriesByChannelStub[id] ?? []
})

watch(
  channel,
  (id) => {
    if (!id || !validIds.has(id as HilosCommunicationsChannelId)) {
      void router.replace('/hilos/communications')
    }
  },
  { immediate: true },
)

useHead(() => ({
  title: channelRow.value
    ? `Deliveries · ${channelRow.value.label} | Hilos | Chat Demo`
    : 'Deliveries | Hilos | Chat Demo',
}))
</script>
