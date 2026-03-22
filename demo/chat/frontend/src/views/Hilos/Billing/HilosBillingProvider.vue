<template>
  <DaemonSectionShell v-if="meta && config" :title="`Billing — ${meta.label}`">
    <p class="text-body-secondary small mb-3">
      Provider configuration (stub). Secrets are masked.
    </p>
    <dl class="row mb-0">
      <dt class="col-sm-3">Provider</dt>
      <dd class="col-sm-9"><code>{{ config.providerId }}</code></dd>
      <dt class="col-sm-3">Mode</dt>
      <dd class="col-sm-9">{{ meta.mode }}</dd>
      <dt class="col-sm-3">Status</dt>
      <dd class="col-sm-9">{{ meta.status }}</dd>
      <dt class="col-sm-3">API key</dt>
      <dd class="col-sm-9 text-break">{{ config.apiKeyHint }}</dd>
      <dt class="col-sm-3">Webhook secret</dt>
      <dd class="col-sm-9 text-break">{{ config.webhookSecretHint }}</dd>
      <dt class="col-sm-3">Currency</dt>
      <dd class="col-sm-9">{{ config.currency }}</dd>
      <dt class="col-sm-3">Statement descriptor</dt>
      <dd class="col-sm-9">{{ config.statementDescriptor }}</dd>
      <dt class="col-sm-3">Notes</dt>
      <dd class="col-sm-9">{{ config.notes }}</dd>
    </dl>
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
  hilosBillingProviderConfigById,
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

const config = computed(() => {
  const id = providerId.value as HilosBillingProviderId
  if (!validIds.has(id)) return null
  return hilosBillingProviderConfigById[id]
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
  title: meta.value ? `Config · ${meta.value.label} | Billing | Hilos | Chat Demo` : 'Billing | Hilos | Chat Demo',
}))
</script>
