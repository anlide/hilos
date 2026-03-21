<template>
  <I18nSectionShell title="Entities">
    <p class="text-body-secondary">Stub list. Open translation for a sample entity.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/translate/entity/${encodeURIComponent(stub.entityId)}`"
        class="list-group-item list-group-item-action"
      >
        Translate entity ({{ stub.entityId }})
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

useHead({ title: 'Entities | I18n | Chat Hilos Demo' })
</script>
