<template>
  <DaemonSectionShell title="Security — Two-factor authentication">
    <p class="text-body-secondary small mb-3">
      2FA enrollment and policy (stub).
    </p>
    <dl class="row mb-0">
      <dt class="col-sm-4">Enrolled users</dt>
      <dd class="col-sm-8">
        {{ hilosSecurityTwoFactorStub.enrolledUsers }} / {{ hilosSecurityTwoFactorStub.totalUsers }}
      </dd>
      <dt class="col-sm-4">Required for admins</dt>
      <dd class="col-sm-8">{{ hilosSecurityTwoFactorStub.requiredForAdmins ? 'Yes' : 'No' }}</dd>
      <dt class="col-sm-4">Recovery codes</dt>
      <dd class="col-sm-8">{{ hilosSecurityTwoFactorStub.recoveryCodesPolicy }}</dd>
      <dt class="col-sm-4">Trusted devices</dt>
      <dd class="col-sm-8">{{ hilosSecurityTwoFactorStub.trustedDevicesCount }}</dd>
      <dt class="col-sm-4">Last policy change</dt>
      <dd class="col-sm-8">{{ hilosSecurityTwoFactorStub.lastPolicyChangeAt }}</dd>
    </dl>
    <div class="mt-3">
      <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/security">Security overview</router-link>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '../Daemon/DaemonSectionShell.vue'
import { hilosSecurityTwoFactorStub } from '../../../constants/hilosSecurityStubs'

useHead({
  title: '2FA | Security | Hilos | Chat Demo',
})
</script>
