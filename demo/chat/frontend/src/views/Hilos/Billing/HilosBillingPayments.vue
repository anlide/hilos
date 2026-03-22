<template>
  <DaemonSectionShell v-if="meta" :title="`Payments — ${meta.label}`">
    <p class="text-body-secondary small mb-3">
      Recent payments for this provider (stub).
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">ID</th>
            <th scope="col">When</th>
            <th scope="col" class="text-end">Amount</th>
            <th scope="col">Currency</th>
            <th scope="col">Status</th>
            <th scope="col">Customer</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in payments" :key="p.id">
            <td><code>{{ p.id }}</code></td>
            <td class="small">{{ p.at }}</td>
            <td class="text-end">{{ p.amount }}</td>
            <td>{{ p.currency }}</td>
            <td>
              <span
                class="badge rounded-pill"
                :class="{
                  'text-bg-success': p.status === 'succeeded',
                  'text-bg-warning': p.status === 'pending',
                  'text-bg-danger': p.status === 'failed',
                }"
              >
                {{ p.status }}
              </span>
            </td>
            <td class="small">{{ p.customerHint }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="payments.length === 0" class="text-body-secondary small mb-0 mt-2">No payments (stub).</p>
    </div>
    <BillingProviderNav class="mt-3" :provider-id="providerId" />
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import DaemonSectionShell from '@/views/Hilos/Daemon/DaemonSectionShell.vue'
import BillingProviderNav from '@/views/Hilos/Billing/BillingProviderNav.vue'
import {
  hilosBillingPaymentsByProviderStub,
  hilosBillingProvidersStub,
  type HilosBillingProviderId,
} from '@/constants/hilosBillingStubs'

const route = useRoute()
const router = useRouter()

const validIds = new Set(
  hilosBillingProvidersStub.map((p) => p.providerId) as HilosBillingProviderId[],
)

const providerId = computed(() => {
  const raw = route.params.providerId
  return typeof raw === 'string' ? raw : ''
})

const meta = computed(() =>
  hilosBillingProvidersStub.find((p) => p.providerId === providerId.value),
)

const payments = computed(() => {
  const id = providerId.value as HilosBillingProviderId
  if (!validIds.has(id)) return []
  return hilosBillingPaymentsByProviderStub[id] ?? []
})

watch(
  providerId,
  (id) => {
    if (!id || !validIds.has(id as HilosBillingProviderId)) {
      void router.replace('/hilos/billing')
    }
  },
  { immediate: true },
)

useHead(() => ({
  title: meta.value ? `Payments · ${meta.value.label} | Billing | Hilos | Chat Demo` : 'Payments | Hilos | Chat Demo',
}))
</script>
