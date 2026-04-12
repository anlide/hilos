<template>
  <DaemonSectionShell v-if="row" :title="`OAuth — ${row.label}`">
    <p class="text-body-secondary small mb-3">
      Provider configuration for SSO-style login (stub).
    </p>
    <dl class="row mb-0">
      <dt class="col-sm-3">Provider</dt>
      <dd class="col-sm-9"><code>{{ row.providerId }}</code> — {{ row.label }}</dd>
      <dt class="col-sm-3">Enabled</dt>
      <dd class="col-sm-9">{{ row.enabled ? 'Yes' : 'No' }}</dd>
      <dt class="col-sm-3">Allow login</dt>
      <dd class="col-sm-9">{{ row.allowLogin ? 'Yes' : 'No' }}</dd>
      <dt class="col-sm-3">Client ID</dt>
      <dd class="col-sm-9 text-break">{{ row.clientIdHint }}</dd>
      <dt class="col-sm-3">Callback URL</dt>
      <dd class="col-sm-9 text-break"><code>{{ row.callbackUrl }}</code></dd>
      <dt class="col-sm-3">Scopes</dt>
      <dd class="col-sm-9">{{ row.scopes }}</dd>
    </dl>
    <div class="mt-3 d-flex flex-wrap gap-2">
      <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/security/oauth">All providers</router-link>
      <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/security">Security overview</router-link>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import {
  hilosSecurityOAuthProvidersStub,
  type HilosSecurityOAuthProviderId,
} from '@/constants/hilosSecurityStubs'

const route = useRoute()
const router = useRouter()

const validIds = new Set(
  hilosSecurityOAuthProvidersStub.map((p) => p.providerId) as HilosSecurityOAuthProviderId[],
)

const providerId = computed(() => {
  const raw = route.params.providerId
  return typeof raw === 'string' ? raw : ''
})

const row = computed(() =>
  hilosSecurityOAuthProvidersStub.find((p) => p.providerId === providerId.value),
)

watch(
  providerId,
  (id) => {
    if (!id || !validIds.has(id as HilosSecurityOAuthProviderId)) {
      void router.replace('/hilos/security/oauth')
    }
  },
  { immediate: true },
)

useHead(() => ({
  title: row.value ? `${row.value.label} | OAuth | Hilos | Chat Demo` : 'OAuth | Hilos | Chat Demo',
}))
</script>
