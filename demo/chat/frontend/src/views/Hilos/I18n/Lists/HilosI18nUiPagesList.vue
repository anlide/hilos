<template>
  <I18nSectionShell title="UI pages">
    <p class="text-body-secondary">Stub list. Open a UI page or jump to translation.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/ui-pages/${encodeURIComponent(stub.uiPageId)}`"
        class="list-group-item list-group-item-action"
      >
        Sample UI page (detail)
      </router-link>
      <router-link
        :to="`/hilos/i18n/translate/ui-page/${encodeURIComponent(stub.uiPageId)}`"
        class="list-group-item list-group-item-action"
      >
        Translate UI page ({{ stub.uiPageId }})
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

useHead({ title: 'UI pages | I18n | Chat Hilos Demo' })
</script>
