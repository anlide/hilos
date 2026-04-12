<template>
  <I18nSectionShell title="Translate UI page">
    <p class="text-body-secondary mb-0">Stub translation workspace for UI page <code>{{ uiPageId }}</code>.</p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'

const route = useRoute()
const uiPageId = computed(() => (typeof route.params.uiPageId === 'string' ? route.params.uiPageId : ''))

useHead({
  title: () => `Translate UI page ${uiPageId.value} | I18n | Chat Hilos Demo`,
})
</script>
