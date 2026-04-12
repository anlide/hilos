<template>
  <DaemonSectionShell title="Security Center">
    <p class="text-body-secondary small mb-3">
      Overview of identity and access posture (stub).
    </p>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="small text-body-secondary">Security score</div>
          <div class="fs-4">{{ hilosSecurityOverviewStub.securityScore }} / 100</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="small text-body-secondary">Open incidents</div>
          <div class="fs-4">{{ hilosSecurityOverviewStub.openIncidents }}</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="small text-body-secondary">Last scan</div>
          <div class="fs-6">{{ hilosSecurityOverviewStub.lastScanAt }}</div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="small text-body-secondary">OAuth login providers enabled</div>
          <div class="fs-4">{{ hilosSecurityOverviewStub.ssoEnabledCount }}</div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="small text-body-secondary">2FA coverage (users)</div>
          <div class="fs-4">{{ hilosSecurityOverviewStub.twoFaCoveragePercent }}%</div>
        </div>
      </div>
    </div>
    <h6 class="text-body-secondary text-uppercase small">More</h6>
    <div class="list-group">
      <router-link class="list-group-item list-group-item-action" to="/hilos/security/2fa">
        Two-factor authentication (2FA)
      </router-link>
      <router-link class="list-group-item list-group-item-action" to="/hilos/security/oauth">
        OAuth login providers
      </router-link>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosSecurityOverviewStub } from '@/constants/hilosSecurityStubs'

useHead({
  title: 'Security Center | Hilos | Chat Demo',
})
</script>
