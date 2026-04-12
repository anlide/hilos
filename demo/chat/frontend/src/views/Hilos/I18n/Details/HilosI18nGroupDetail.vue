<template>
  <I18nSectionShell title="Group">
    <p class="text-body-secondary mb-2">Group <code>{{ groupId }}</code> — items (stub).</p>
    <router-link
      :to="`/hilos/i18n/translate/group/${encodeURIComponent(groupId)}/item/${encodeURIComponent(stub.itemId)}`"
    >
      Translate group item {{ stub.itemId }}
    </router-link>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import I18nSectionShell from '@hilos/sdk/views/Hilos/I18n/I18nSectionShell.vue'
import { hilosI18nStub as stub } from '@/constants/hilosI18nStubs'

const route = useRoute()
const groupId = computed(() => (typeof route.params.groupId === 'string' ? route.params.groupId : ''))

useHead({
  title: () => `Group ${groupId.value} | I18n | Chat Hilos Demo`,
})
</script>
