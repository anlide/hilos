<template>
  <I18nSectionShell title="Action">
    <p class="text-body-secondary mb-2">Action <code>{{ actionId }}</code> — errors (stub).</p>
    <router-link
      :to="`/hilos/i18n/translate/action/${encodeURIComponent(actionId)}/error/${encodeURIComponent(stub.errorId)}`"
    >
      Translate error {{ stub.errorId }}
    </router-link>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'
import { hilosI18nStub as stub } from '../../../../constants/hilosI18nStubs'

const route = useRoute()
const actionId = computed(() => (typeof route.params.actionId === 'string' ? route.params.actionId : ''))

useHead({
  title: () => `Action ${actionId.value} | I18n | Chat Hilos Demo`,
})
</script>
