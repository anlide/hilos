<template>
  <I18nSectionShell title="Actions">
    <p class="text-body-secondary">Stub list.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/actions/${encodeURIComponent(stub.actionId)}`"
        class="list-group-item list-group-item-action"
      >
        Sample action (detail)
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

useHead({ title: 'Actions | I18n | Chat Hilos Demo' })
</script>
