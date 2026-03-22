<template>
  <DaemonSectionShell title="Billing">
    <p class="text-body-secondary small mb-3">
      Connected payment systems (stub). Open a provider for config, payments, and refunds.
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">Provider</th>
            <th scope="col">Mode</th>
            <th scope="col">Status</th>
            <th scope="col">Last webhook</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in hilosBillingProvidersStub" :key="row.providerId">
            <td class="fw-semibold">{{ row.label }}</td>
            <td><span class="badge text-bg-secondary">{{ row.mode }}</span></td>
            <td>
              <span
                class="badge rounded-pill"
                :class="{
                  'text-bg-success': row.status === 'connected',
                  'text-bg-danger': row.status === 'error',
                  'text-bg-secondary': row.status === 'disabled',
                }"
              >
                {{ row.status }}
              </span>
            </td>
            <td class="small text-body-secondary">{{ row.lastWebhookAt }}</td>
            <td class="text-end text-nowrap">
              <router-link
                class="btn btn-sm btn-outline-primary me-1"
                :to="`/hilos/billing/${row.providerId}`"
              >
                Config
              </router-link>
              <router-link
                class="btn btn-sm btn-outline-secondary me-1"
                :to="`/hilos/billing/${row.providerId}/payments`"
              >
                Payments
              </router-link>
              <router-link
                class="btn btn-sm btn-outline-secondary"
                :to="`/hilos/billing/${row.providerId}/refunds`"
              >
                Refunds
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
import DaemonSectionShell from '@/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosBillingProvidersStub } from '@/constants/hilosBillingStubs'

useHead({
  title: 'Billing | Hilos | Chat Demo',
})
</script>
