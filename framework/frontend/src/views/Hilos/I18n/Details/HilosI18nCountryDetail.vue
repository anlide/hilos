<template>
  <I18nSectionShell title="Country">
    <p class="text-body-secondary mb-2">Country <code>{{ countryId }}</code> — names (stub).</p>
    <ul class="list-unstyled mb-0">
      <li class="mb-1"><code>en: United States</code></li>
      <li><code>de: Vereinigte Staaten</code></li>
    </ul>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'

const route = useRoute()
const countryId = computed(() => (typeof route.params.countryId === 'string' ? route.params.countryId : ''))

useHead({
  title: () => `Country ${countryId.value} | I18n | Chat Hilos Demo`,
})
</script>
