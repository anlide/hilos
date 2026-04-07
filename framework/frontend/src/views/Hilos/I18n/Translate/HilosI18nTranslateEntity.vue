<template>
  <I18nSectionShell title="Translate entity">
    <p class="text-body-secondary mb-0">Stub translation workspace for entity <code>{{ entityId }}</code>.</p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'

const route = useRoute()
const entityId = computed(() => (typeof route.params.entityId === 'string' ? route.params.entityId : ''))

useHead({
  title: () => `Translate entity ${entityId.value} | I18n | Chat Hilos Demo`,
})
</script>
