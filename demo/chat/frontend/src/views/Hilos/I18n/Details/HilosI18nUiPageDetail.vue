<template>
  <I18nSectionShell title="UI page">
    <p class="text-body-secondary mb-2">UI page <code>{{ uiPageId }}</code> — items (stub).</p>
    <ul class="list-unstyled mb-3">
      <li class="mb-2">
        <router-link
          :to="`/hilos/i18n/translate/ui-page/${encodeURIComponent(uiPageId)}/item/${encodeURIComponent(stub.itemId)}`"
        >
          Item {{ stub.itemId }} (translate)
        </router-link>
      </li>
    </ul>
    <router-link
      class="btn btn-sm btn-outline-secondary"
      :to="`/hilos/i18n/translate/ui-page/${encodeURIComponent(uiPageId)}`"
    >
      Translate this UI page
    </router-link>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

const route = useRoute()
const uiPageId = computed(() => (typeof route.params.uiPageId === 'string' ? route.params.uiPageId : ''))

useHead({
  title: () => `UI page ${uiPageId.value} | I18n | Chat Hilos Demo`,
})
</script>
