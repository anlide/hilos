<template>
  <I18nSectionShell title="Translate action error">
    <p class="text-body-secondary mb-0">
      Stub translation for action <code>{{ actionId }}</code>, error <code>{{ errorId }}</code>.
    </p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@/views/Hilos/I18n/I18nSectionShell.vue'

const route = useRoute()
const actionId = computed(() => (typeof route.params.actionId === 'string' ? route.params.actionId : ''))
const errorId = computed(() => (typeof route.params.errorId === 'string' ? route.params.errorId : ''))

useHead({
  title: () => `Translate action error ${errorId.value} | I18n | Chat Hilos Demo`,
})
</script>
