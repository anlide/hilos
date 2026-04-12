<template>
  <I18nSectionShell title="Emails">
    <p class="text-body-secondary">Stub list.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/translate/email/${encodeURIComponent(stub.emailId)}`"
        class="list-group-item list-group-item-action"
      >
        Translate email ({{ stub.emailId }})
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

useHead({ title: 'Emails | I18n | Chat Hilos Demo' })
</script>
