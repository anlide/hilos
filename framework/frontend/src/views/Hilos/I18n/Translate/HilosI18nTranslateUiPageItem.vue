<template>
  <I18nSectionShell title="Translate UI page item">
    <p class="text-body-secondary mb-0">
      Stub translation for UI page <code>{{ uiPageId }}</code>, item <code>{{ itemId }}</code>.
    </p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'

const route = useRoute()
const uiPageId = computed(() => (typeof route.params.uiPageId === 'string' ? route.params.uiPageId : ''))
const itemId = computed(() => (typeof route.params.itemId === 'string' ? route.params.itemId : ''))

useHead({
  title: () => `Translate UI item ${itemId.value} | I18n | Chat Hilos Demo`,
})
</script>
