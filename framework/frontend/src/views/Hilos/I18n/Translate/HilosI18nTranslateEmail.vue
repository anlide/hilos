<template>
  <I18nSectionShell title="Translate email">
    <p class="text-body-secondary mb-0">Stub translation workspace for email <code>{{ emailId }}</code>.</p>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'

const route = useRoute()
const emailId = computed(() => (typeof route.params.emailId === 'string' ? route.params.emailId : ''))

useHead({
  title: () => `Translate email ${emailId.value} | I18n | Chat Hilos Demo`,
})
</script>
