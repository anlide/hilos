<template>
  <DaemonSectionShell v-if="meta" :title="`Refunds — ${meta.label}`">
    <p class="text-body-secondary small mb-3">
      Refunds and partial refunds for this provider (stub).
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">Refund ID</th>
            <th scope="col">When</th>
            <th scope="col">Payment ID</th>
            <th scope="col" class="text-end">Amount</th>
            <th scope="col">Currency</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in refunds" :key="r.id">
            <td><code>{{ r.id }}</code></td>
            <td class="small">{{ r.at }}</td>
            <td><code>{{ r.paymentId }}</code></td>
            <td class="text-end">{{ r.amount }}</td>
            <td>{{ r.currency }}</td>
            <td>
              <span
                class="badge rounded-pill"
                :class="{
                  'text-bg-success': r.status === 'succeeded',
                  'text-bg-warning': r.status === 'pending',
                  'text-bg-danger': r.status === 'failed',
                }"
              >
                {{ r.status }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="refunds.length === 0" class="text-body-secondary small mb-0 mt-2">No refunds (stub).</p>
    </div>
    <BillingProviderNav class="mt-3" :provider-id="providerId" />
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import DaemonSectionShell from '../Daemon/DaemonSectionShell.vue'
import BillingProviderNav from './BillingProviderNav.vue'
import {
  hilosBillingRefundsByProviderStub,
  hilosBillingProvidersStub,
  type HilosBillingProviderId,
} from '../../../constants/hilosBillingStubs'

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

const refunds = computed(() => {
  const id = providerId.value as HilosBillingProviderId
  if (!validIds.has(id)) return []
  return hilosBillingRefundsByProviderStub[id] ?? []
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
  title: meta.value ? `Refunds · ${meta.value.label} | Billing | Hilos | Chat Demo` : 'Refunds | Hilos | Chat Demo',
}))
</script>
