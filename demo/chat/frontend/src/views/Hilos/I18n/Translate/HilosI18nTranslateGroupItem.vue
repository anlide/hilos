<template>
  <I18nSectionShell title="Translate group item">
    <p class="text-body-secondary mb-0">
      Stub translation for group <code>{{ groupId }}</code>, item <code>{{ itemId }}</code>.
    </p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@/views/Hilos/I18n/I18nSectionShell.vue'

const route = useRoute()
const groupId = computed(() => (typeof route.params.groupId === 'string' ? route.params.groupId : ''))
const itemId = computed(() => (typeof route.params.itemId === 'string' ? route.params.itemId : ''))

useHead({
  title: () => `Translate group item ${itemId.value} | I18n | Chat Hilos Demo`,
})
</script>
