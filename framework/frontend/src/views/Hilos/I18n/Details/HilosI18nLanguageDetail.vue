<template>
  <I18nSectionShell title="Language">
    <p class="text-body-secondary mb-0">Language <code>{{ languageId }}</code> — locales (stub).</p>
    <ul class="list-unstyled mt-3 mb-0">
      <li class="mb-2">
        <span class="badge text-bg-secondary me-2">locale</span>
        <code>{{ languageId }}-US</code>
      </li>
      <li>
        <span class="badge text-bg-secondary me-2">locale</span>
        <code>{{ languageId }}-GB</code>
      </li>
    </ul>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'

const route = useRoute()
const languageId = computed(() => (typeof route.params.languageId === 'string' ? route.params.languageId : ''))

useHead({
  title: () => `Language ${languageId.value} | I18n | Chat Hilos Demo`,
})
</script>
