<template>
  <I18nSectionShell title="Groups">
    <p class="text-body-secondary">Stub list.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/groups/${encodeURIComponent(stub.groupId)}`"
        class="list-group-item list-group-item-action"
      >
        Sample group (detail)
      </router-link>
      <router-link
        :to="`/hilos/i18n/translate/group/${encodeURIComponent(stub.groupId)}`"
        class="list-group-item list-group-item-action"
      >
        Translate group ({{ stub.groupId }})
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'
import { hilosI18nStub as stub } from '../../../../constants/hilosI18nStubs'

useHead({ title: 'Groups | I18n | Chat Hilos Demo' })
</script>
