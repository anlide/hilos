<template>
  <I18nSectionShell title="Languages">
    <p class="text-body-secondary">Stub list. Open a language to see locales.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/languages/${encodeURIComponent(stub.languageId)}`"
        class="list-group-item list-group-item-action"
      >
        Sample language ({{ stub.languageId }})
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

useHead({
  title: 'Languages | I18n | Chat Hilos Demo',
})
</script>
