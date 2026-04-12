<template>
  <I18nSectionShell title="Translate group">
    <p class="text-body-secondary mb-0">Stub translation workspace for group <code>{{ groupId }}</code>.</p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'

const route = useRoute()
const groupId = computed(() => (typeof route.params.groupId === 'string' ? route.params.groupId : ''))

useHead({
  title: () => `Translate group ${groupId.value} | I18n | Chat Hilos Demo`,
})
</script>
