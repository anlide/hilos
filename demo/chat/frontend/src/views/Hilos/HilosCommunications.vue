<template>
  <DaemonSectionShell title="Communications">
    <p class="text-body-secondary small mb-3">
      Channels for outbound messages: email, SMS, push, Telegram, Slack (stub data).
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">Channel</th>
            <th scope="col">Provider</th>
            <th scope="col">Status</th>
            <th scope="col">Last delivery</th>
            <th scope="col" class="text-end">Queue</th>
            <th scope="col" class="text-end">Templates</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in hilosCommunicationsChannelsStub" :key="row.channelId">
            <td class="fw-semibold">{{ row.label }}</td>
            <td class="small">{{ row.providerName }}</td>
            <td>
              <span
                class="badge rounded-pill"
                :class="{
                  'text-bg-success': row.status === 'healthy',
                  'text-bg-warning': row.status === 'degraded',
                  'text-bg-secondary': row.status === 'disabled',
                }"
              >
                {{ row.status }}
              </span>
            </td>
            <td class="small text-body-secondary">{{ row.lastDeliveryAt }}</td>
            <td class="text-end">{{ row.queueDepth }}</td>
            <td class="text-end">{{ row.templatesCount }}</td>
            <td class="text-end text-nowrap">
              <router-link
                class="btn btn-sm btn-outline-primary me-1"
                :to="`/hilos/communications/${row.channelId}`"
              >
                Config
              </router-link>
              <router-link
                class="btn btn-sm btn-outline-secondary"
                :to="`/hilos/communications/${row.channelId}/deliveries`"
              >
                Deliveries
              </router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosCommunicationsChannelsStub } from '@/constants/hilosCommunicationsStubs'

useHead({
  title: 'Communications | Hilos | Chat Demo',
})
</script>
